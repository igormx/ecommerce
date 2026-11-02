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
use App\Domain\Persistence\TransactionManagerInterface;
use App\Domain\Product\Exception\InsufficientStockException;
use App\Domain\Product\Exception\ProductNotFoundException;
use App\Domain\Product\Product;
use App\Domain\Product\ProductRepositoryInterface;
use DateTimeImmutable;

class PurchaseService
{
    /** Maximum number of distinct products in one purchase. */
    public const MAX_LINES = 50;
    /** Maximum quantity of a single product in one purchase. */
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
        $byId = [];
        foreach ($this->products->findByIds($ids) as $product) {
            if ($product->active && $product->id !== null) {
                $byId[$product->id] = $product;
            }
        }

        $items = [];
        $total = 0;
        foreach ($lines as $productId => $quantity) {
            $productId = (int) $productId;
            $quantity = (int) $quantity;
            /** @var Product|null $product */
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

        $now = new DateTimeImmutable();

        // Sort by product id so concurrent purchases lock rows in the same order (avoids InnoDB deadlocks).
        usort($items, static fn (OrderItem $a, OrderItem $b): int => $a->productId <=> $b->productId);

        // Order is decrement -> charge: stock is secured first so a customer is never
        // charged for goods we cannot deliver. A declined charge rolls the decrement back.
        try {
            /** @var Order $order */
            $order = $this->transactions->run(function () use ($items, $total, $now): Order {
                foreach ($items as $item) {
                    if (!$this->products->decrementStock($item->productId, $item->quantity)) {
                        // Lost a race: stock changed since we read it.
                        throw new InsufficientStockException($item->productId, $item->quantity, 0);
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
