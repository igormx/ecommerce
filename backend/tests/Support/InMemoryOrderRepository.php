<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Order\Exception\OrderNotFoundException;
use App\Domain\Order\Order;
use App\Domain\Order\OrderItem;
use App\Domain\Order\OrderRepositoryInterface;

/**
 * In-memory OrderRepositoryInterface fake for tests that run the real Slim app.
 */
class InMemoryOrderRepository implements OrderRepositoryInterface
{
    /** @var array<int, Order> */
    private array $orders = [];

    private int $nextId = 1;

    public function save(Order $order): Order
    {
        $id = $this->nextId++;
        $items = array_map(
            static fn (OrderItem $i): OrderItem => new OrderItem(
                $i->id ?? $id * 100 + $i->productId,
                $id,
                $i->productId,
                $i->productName,
                $i->sku,
                $i->quantity,
                $i->unitPriceCents,
            ),
            $order->items,
        );

        return $this->orders[$id] = new Order($id, $order->status, $order->totalCents, $order->createdAt, $items);
    }

    public function findById(int $id): Order
    {
        return $this->orders[$id] ?? throw OrderNotFoundException::forId($id);
    }

    public function count(): int
    {
        return count($this->orders);
    }
}
