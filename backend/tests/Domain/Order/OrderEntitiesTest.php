<?php

declare(strict_types=1);

namespace Tests\Domain\Order;

use App\Domain\Order\Order;
use App\Domain\Order\OrderItem;
use App\Domain\Order\OrderStatus;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class OrderEntitiesTest extends TestCase
{
    public function testOrderItemKeepsConstructorValues(): void
    {
        $item = new OrderItem(1, 2, 3, 'Widget', 'W-1', 4, 500);

        $this->assertSame(1, $item->id);
        $this->assertSame(2, $item->orderId);
        $this->assertSame(3, $item->productId);
        $this->assertSame('Widget', $item->productName);
        $this->assertSame('W-1', $item->sku);
        $this->assertSame(4, $item->quantity);
        $this->assertSame(500, $item->unitPriceCents);
    }

    public function testOrderKeepsConstructorValues(): void
    {
        $at = new DateTimeImmutable('2024-01-01');
        $item = new OrderItem(null, null, 3, 'Widget', 'W-1', 4, 500);
        $order = new Order(9, OrderStatus::FAILED, 2000, $at, [$item]);

        $this->assertSame(9, $order->id);
        $this->assertSame(OrderStatus::FAILED, $order->status);
        $this->assertSame(2000, $order->totalCents);
        $this->assertSame($at, $order->createdAt);
        $this->assertSame([$item], $order->items);
    }

    public function testOrderItemsDefaultToEmpty(): void
    {
        $order = new Order(null, OrderStatus::PAID, 0, new DateTimeImmutable());

        $this->assertNull($order->id);
        $this->assertSame([], $order->items);
    }
}
