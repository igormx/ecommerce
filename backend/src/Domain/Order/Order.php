<?php

declare(strict_types=1);

namespace App\Domain\Order;

use DateTimeImmutable;

/**
 * Data-only, immutable Order entity. Maps to the `orders` table.
 * Money is represented as integer cents (`totalCents`).
 */
final class Order
{
    /**
     * @param OrderItem[] $items
     */
    public function __construct(
        public readonly ?int $id,
        public readonly OrderStatus $status,
        public readonly int $totalCents,
        public readonly DateTimeImmutable $createdAt,
        public readonly array $items = [],
    ) {
    }
}
