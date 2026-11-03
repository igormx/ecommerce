<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Product\Exception\DuplicateSkuException;
use App\Domain\Product\Exception\ProductNotFoundException;
use App\Domain\Product\Product;
use App\Domain\Product\ProductRepositoryInterface;

/**
 * In-memory ProductRepositoryInterface fake for tests that run the real Slim app.
 * Mirrors the MySQL implementation: findById/findBySku return inactive rows too,
 * while findAll/search return only active ones.
 */
class InMemoryProductRepository implements ProductRepositoryInterface
{
    /** @var array<int, Product> */
    private array $products = [];

    private int $nextId = 1;

    public function __construct(Product ...$products)
    {
        foreach ($products as $product) {
            $this->save($product);
        }
    }

    public function findAll(int $page, int $size): array
    {
        return array_slice($this->active(), ($page - 1) * $size, $size);
    }

    public function countAll(): int
    {
        return count($this->active());
    }

    public function findCategories(): array
    {
        $categories = [];
        foreach ($this->active() as $product) {
            if ($product->category !== null) {
                $categories[$product->category] = true;
            }
        }
        $categories = array_map('strval', array_keys($categories));
        sort($categories, SORT_STRING);

        return $categories;
    }

    public function findById(int $id): Product
    {
        return $this->products[$id] ?? throw ProductNotFoundException::forId($id);
    }

    public function findBySku(string $sku): Product
    {
        foreach ($this->products as $product) {
            if ($product->sku === $sku) {
                return $product;
            }
        }

        throw ProductNotFoundException::forSku($sku);
    }

    public function save(Product $product): Product
    {
        foreach ($this->products as $existing) {
            if ($existing->sku === $product->sku && $existing->id !== $product->id) {
                throw new DuplicateSkuException($product->sku);
            }
        }

        $id = $product->id ?? $this->nextId;
        $this->nextId = max($this->nextId, $id + 1);

        $saved = new Product(
            $id,
            $product->sku,
            $product->name,
            $product->description,
            $product->category,
            $product->priceCents,
            $product->stock,
            $product->weightKg,
            $product->active,
            $product->createdAt,
            $product->updatedAt,
        );
        $this->products[$id] = $saved;

        return $saved;
    }

    public function deactivate(int $id): void
    {
        $p = $this->findById($id);
        $this->products[$id] = new Product(
            $p->id,
            $p->sku,
            $p->name,
            $p->description,
            $p->category,
            $p->priceCents,
            $p->stock,
            $p->weightKg,
            false,
            $p->createdAt,
            $p->updatedAt,
        );
    }

    public function search(string $query, ?string $category, int $page, int $size): array
    {
        return array_slice($this->searchMatches($query, $category), ($page - 1) * $size, $size);
    }

    public function countSearch(string $query, ?string $category): int
    {
        return count($this->searchMatches($query, $category));
    }

    public function findByIds(array $ids): array
    {
        return array_values(array_filter(
            $this->products,
            fn (Product $p): bool => in_array($p->id, $ids, true)
        ));
    }

    public function decrementStock(int $productId, int $quantity): bool
    {
        $p = $this->products[$productId] ?? null;
        if ($p === null || !$p->active || $p->stock < $quantity) {
            return false;
        }

        $this->products[$productId] = new Product(
            $p->id,
            $p->sku,
            $p->name,
            $p->description,
            $p->category,
            $p->priceCents,
            $p->stock - $quantity,
            $p->weightKg,
            $p->active,
            $p->createdAt,
            $p->updatedAt,
        );

        return true;
    }

    /**
     * @return list<Product>
     */
    private function active(): array
    {
        return array_values(array_filter($this->products, fn (Product $p): bool => $p->active));
    }

    /**
     * Shared by search() and countSearch(), so the two cannot drift.
     *
     * @return list<Product>
     */
    private function searchMatches(string $query, ?string $category): array
    {
        return array_values(array_filter(
            $this->active(),
            fn (Product $p): bool => ($category === null || $p->category === $category)
                && (
                    stripos($p->name, $query) !== false
                    || stripos((string) $p->description, $query) !== false
                    || stripos((string) $p->category, $query) !== false
                )
        ));
    }
}
