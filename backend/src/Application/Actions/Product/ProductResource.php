<?php

declare(strict_types=1);

namespace App\Application\Actions\Product;

use App\Domain\Product\Product;
use DateTimeInterface;

/**
 * Maps a Product entity to its JSON representation.
 */
final class ProductResource
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(Product $product): array
    {
        return [
            'id' => $product->id,
            'sku' => $product->sku,
            'name' => $product->name,
            'description' => $product->description,
            'category' => $product->category,
            'priceCents' => $product->priceCents,
            'stock' => $product->stock,
            'weightKg' => $product->weightKg,
            'createdAt' => $product->createdAt->format(DateTimeInterface::ATOM),
            'updatedAt' => $product->updatedAt->format(DateTimeInterface::ATOM),
        ];
    }

    /**
     * @param Product[] $products
     * @return list<array<string, mixed>>
     */
    public static function collection(array $products): array
    {
        return array_map(self::toArray(...), array_values($products));
    }
}
