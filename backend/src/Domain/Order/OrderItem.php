<?php

declare(strict_types=1);

namespace App\Domain\Order;

/**
 * Snapshots the product's name, sku and unit price at purchase time, so it
 * stays accurate even if the Product later changes or is deactivated.
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
