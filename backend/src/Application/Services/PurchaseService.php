<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Application\Services\Exception\InvalidPurchaseException;
use App\Application\Services\Exception\PaymentDeclinedRollback;
use App\Domain\Order\Order;
use App\Domain\Order\OrderItem;
use App\Domain\Order\OrderRepositoryInterface;
use App\Domain\Order\OrderStatus;
use App\Domain\Payment\PaymentGatewayInterface;
use App\Domain\Persistence\Exception\TransientTransactionException;
use App\Domain\Persistence\TransactionManagerInterface;
use App\Domain\Product\Exception\InsufficientStockException;
use App\Domain\Product\Exception\ProductNotFoundException;
use App\Domain\Product\ProductRepositoryInterface;
use DateTimeImmutable;

class PurchaseService
{
    public const MAX_LINES = 50;
    public const MAX_QUANTITY = 1000;

    public function __construct(
        private readonly ProductRepositoryInterface $products,
        private readonly OrderRepositoryInterface $orders,
        private readonly PaymentGatewayInterface $payments,
        private readonly TransactionManagerInterface $transactions,
    ) {
    }

    /**
     * @param array<int|string, mixed> $lines Map of productId => quantity, validated at runtime.
     * @return Order PAID order, or FAILED order if the payment was declined.
     *
     * @throws InvalidPurchaseException
     * @throws ProductNotFoundException
     * @throws InsufficientStockException
     * @throws TransientTransactionException When a deadlock/lock-wait persisted through all retries.
     */
    public function purchase(array $lines): Order
    {
        if ($lines === []) {
            throw InvalidPurchaseException::emptyPurchase();
        }

        if (count($lines) > self::MAX_LINES) {
            throw InvalidPurchaseException::tooManyLines(self::MAX_LINES);
        }

        foreach ($lines as $productId => $quantity) {
            if (!is_int($productId) || !is_int($quantity)) {
                throw InvalidPurchaseException::nonIntegerLine();
            }
            if ($quantity <= 0 || $quantity > self::MAX_QUANTITY) {
                throw InvalidPurchaseException::invalidQuantity($productId, $quantity);
            }
        }

        $ids = array_map('intval', array_keys($lines));
        sort($ids);
        $now = new DateTimeImmutable();

        /** @var list<OrderItem> $items */
        $items = [];
        $total = 0;

        // Everything authoritative (price, name, sku, active, stock) is read inside the
        // transaction from rows locked with SELECT ... FOR UPDATE, in ascending id order so
        // concurrent purchases lock in the same order (avoids InnoDB deadlocks). If the
        // transaction manager retries after a deadlock, the closure re-runs from scratch.
        //
        // Order is lock -> decrement -> charge: stock is secured first so a customer is never
        // charged for goods we cannot deliver. A declined charge rolls the decrement back.
        // Known limitation (out of scope): charging while holding row locks keeps them held
        // for the gateway call; acceptable with the fake gateway.
        try {
            /** @var Order $order */
            $order = $this->transactions->run(function () use ($ids, $lines, $now, &$items, &$total): Order {
                $items = [];
                $total = 0;

                $byId = [];
                foreach ($this->products->findByIdsForUpdate($ids) as $product) {
                    if ($product->active && $product->id !== null) {
                        $byId[$product->id] = $product;
                    }
                }

                foreach ($lines as $productId => $quantity) {
                    $productId = (int) $productId;
                    $quantity = (int) $quantity;
                    $product = $byId[$productId] ?? null;
                    if ($product === null) {
                        throw ProductNotFoundException::forId($productId);
                    }
                    if ($product->stock < $quantity) {
                        throw new InsufficientStockException($productId, $quantity, $product->stock);
                    }

                    $items[] = new OrderItem(
                        null,
                        null,
                        $productId,
                        $product->name,
                        $product->sku,
                        $quantity,
                        $product->priceCents,
                    );
                    $total += $product->priceCents * $quantity;
                }

                usort($items, static fn (OrderItem $a, OrderItem $b): int => $a->productId <=> $b->productId);

                foreach ($items as $item) {
                    // Defense in depth: the rows are locked, so this should always succeed.
                    if (!$this->products->decrementStock($item->productId, $item->quantity)) {
                        $available = 0;
                        foreach ($this->products->findByIdsForUpdate([$item->productId]) as $current) {
                            $available = $current->active ? $current->stock : 0;
                        }
                        throw new InsufficientStockException($item->productId, $item->quantity, $available);
                    }
                }

                if (!$this->payments->charge($total)) {
                    throw new PaymentDeclinedRollback();
                }

                return $this->orders->save(new Order(null, OrderStatus::PAID, $total, $now, $items));
            });
        } catch (PaymentDeclinedRollback) {
            return $this->orders->save(new Order(null, OrderStatus::FAILED, $total, $now, $items));
        }

        return $order;
    }
}
