<?php

declare(strict_types=1);

namespace App\Domain\Product;

use DateTimeImmutable;

/**
 * Data-only, immutable Product entity. Maps to the `products` table.
 * Money is represented as integer cents (`priceCents`).
 */
final class Product
{
    public function __construct(
        public readonly ?int $id,
        public readonly string $sku,
        public readonly string $name,
        public readonly ?string $description,
        public readonly ?string $category,
        public readonly int $priceCents,
        public readonly int $stock,
        public readonly ?float $weightKg,
        public readonly bool $active,
        public readonly DateTimeImmutable $createdAt,
        public readonly DateTimeImmutable $updatedAt,
        /** Optimistic-lock counter: bumped by every write to the row (edits and stock decrements). */
        public readonly int $version = 1,
    ) {
    }
}
