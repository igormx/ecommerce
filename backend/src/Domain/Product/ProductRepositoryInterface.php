<?php

declare(strict_types=1);

namespace App\Domain\Product;

use App\Domain\Product\Exception\DuplicateSkuException;
use App\Domain\Product\Exception\ProductNotFoundException;

interface ProductRepositoryInterface
{
    /**
     * Returns a page of active products.
     *
     * @return Product[]
     */
    public function findAll(int $page, int $size): array;

    /**
     * @throws ProductNotFoundException
     */
    public function findById(int $id): Product;

    /**
     * @throws ProductNotFoundException
     */
    public function findBySku(string $sku): Product;

    /**
     * Inserts the product if it has no id, or updates the existing row
     * otherwise. Returns the persisted product (with its id populated).
     *
     * @throws DuplicateSkuException
     */
    public function save(Product $product): Product;

    /**
     * Soft-deletes the product by setting `active = 0`.
     *
     * @throws ProductNotFoundException
     */
    public function deactivate(int $id): void;

    /**
     * Full-text search over name/description/category, with an optional
     * exact category filter. Returns only active products.
     *
     * @return Product[]
     */
    public function search(string $query, ?string $category, int $page, int $size): array;

    /**
     * @param int[] $ids
     * @return Product[]
     */
    public function findByIds(array $ids): array;

    /**
     * Atomically decrements stock:
     * UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ? AND active = 1.
     * Returns true iff a row was affected. Does not manage transactions; the
     * caller controls them.
     */
    public function decrementStock(int $productId, int $quantity): bool;
}
