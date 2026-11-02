<?php

declare(strict_types=1);

namespace App\Application\Actions\Order;

use App\Domain\Order\Order;
use App\Domain\Order\OrderItem;
use DateTimeInterface;

/**
 * Maps an Order entity (and its items) to its JSON representation.
 */
final class OrderResource
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(Order $order): array
    {
        return [
            'id' => $order->id,
            'status' => $order->status->value,
            'totalCents' => $order->totalCents,
            'createdAt' => $order->createdAt->format(DateTimeInterface::ATOM),
            'items' => array_map(
                static fn (OrderItem $item): array => [
                    'productId' => $item->productId,
                    'sku' => $item->sku,
                    'name' => $item->productName,
                    'quantity' => $item->quantity,
                    'unitPriceCents' => $item->unitPriceCents,
                ],
                array_values($order->items),
            ),
        ];
    }
}
