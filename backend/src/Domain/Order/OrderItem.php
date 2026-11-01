<?php

declare(strict_types=1);

namespace App\Domain\Order;

/**
 * Data-only, immutable OrderItem entity. Maps to the `order_items` table.
 * Snapshots the product's name, sku and unit price at purchase time, so it
 * stays accurate even if the referenced Product later changes or is
 * deactivated. Money is represented as integer cents (`unitPriceCents`).
 */
final class OrderItem
{
    public function __construct(
        public readonly ?int $id,
        public readonly ?int $orderId,
        public readonly int $productId,
        public readonly string $productName,
        public readonly string $sku,
        public readonly int $quantity,
        public readonly int $unitPriceCents,
    ) {
    }
}
