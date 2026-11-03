<?php

declare(strict_types=1);

namespace App\Domain\Product;

use App\Domain\Product\Exception\DuplicateSkuException;
use App\Domain\Product\Exception\ProductNotFoundException;
use App\Domain\Product\Exception\StaleProductException;

interface ProductRepositoryInterface
{
    /**
     * Returns a page of active products.
     *
     * @return Product[]
     */
    public function findAll(int $page, int $size): array;

    /**
     * Count of active products, matching findAll()'s filter.
     */
    public function countAll(): int;

    /**
     * Distinct, non-null categories of active products, sorted ascending.
     *
     * @return list<string>
     */
    public function findCategories(): array;

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
     * An update is conditional on `$product->version` still being the stored
     * version, and bumps it.
     *
     * @throws DuplicateSkuException
     * @throws StaleProductException
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
     * Count of active products matching search()'s filter, same semantics
     * for $query and $category.
     */
    public function countSearch(string $query, ?string $category): int;

    /**
     * @param int[] $ids
     * @return Product[]
     */
    public function findByIds(array $ids): array;

    /**
     * Locks and returns the rows (active or not) for the given ids with
     * SELECT ... FOR UPDATE, in ascending id order. Unknown ids are omitted.
     * Must be called inside a transaction; the locks last until it ends.
     *
     * @param int[] $ids
     * @return Product[] ordered by id
     */
    public function findByIdsForUpdate(array $ids): array;

    /**
     * Atomically decrements stock:
     * UPDATE products SET stock = stock - ?, version = version + 1 WHERE id = ? AND stock >= ? AND active = 1.
     * Returns true iff a row was affected. Does not manage transactions; the
     * caller controls them.
     */
    public function decrementStock(int $productId, int $quantity): bool;
}
