<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Application\Services\Exception\ValidationException;
use App\Domain\Product\Exception\DuplicateSkuException;
use App\Domain\Product\Exception\ProductNotFoundException;
use App\Domain\Product\Product;
use App\Domain\Product\ProductRepositoryInterface;
use DateTimeImmutable;

class ProductService
{
    public const MAX_PAGE_SIZE = 100;

    public function __construct(private readonly ProductRepositoryInterface $products)
    {
    }

    /**
     * @return Product[]
     */
    public function list(int $page = 1, int $size = 20): array
    {
        return $this->products->findAll(max(1, $page), $this->clampSize($size));
    }

    /**
     * @throws ProductNotFoundException
     */
    public function get(int $id): Product
    {
        return $this->products->findById($id);
    }

    /**
     * @throws ProductNotFoundException
     */
    public function getBySku(string $sku): Product
    {
        return $this->products->findBySku(trim($sku));
    }

    /**
     * @throws ValidationException
     * @throws DuplicateSkuException
     */
    public function create(
        string $sku,
        string $name,
        ?string $description,
        ?string $category,
        int $priceCents,
        int $stock,
        ?float $weightKg = null,
    ): Product {
        $sku = trim($sku);
        $name = trim($name);
        $this->validate($sku, $name, $priceCents, $stock, $weightKg);

        if ($this->skuExists($sku)) {
            throw new DuplicateSkuException($sku);
        }

        $now = new DateTimeImmutable();

        return $this->products->save(new Product(
            null,
            $sku,
            $name,
            $this->nullIfBlank($description),
            $this->nullIfBlank($category),
            $priceCents,
            $stock,
            $weightKg,
            true,
            $now,
            $now,
        ));
    }

    /**
     * Full update of the editable fields.
     *
     * @throws ProductNotFoundException
     * @throws ValidationException
     * @throws DuplicateSkuException
     */
    public function update(
        int $id,
        string $sku,
        string $name,
        ?string $description,
        ?string $category,
        int $priceCents,
        int $stock,
        ?float $weightKg = null,
    ): Product {
        $existing = $this->products->findById($id);

        $sku = trim($sku);
        $name = trim($name);
        $this->validate($sku, $name, $priceCents, $stock, $weightKg);

        if ($sku !== $existing->sku && $this->skuExists($sku)) {
            throw new DuplicateSkuException($sku);
        }

        return $this->products->save(new Product(
            $existing->id,
            $sku,
            $name,
            $this->nullIfBlank($description),
            $this->nullIfBlank($category),
            $priceCents,
            $stock,
            $weightKg,
            $existing->active,
            $existing->createdAt,
            new DateTimeImmutable(),
        ));
    }

    /**
     * Soft delete.
     *
     * @throws ProductNotFoundException
     */
    public function deactivate(int $id): void
    {
        $this->products->deactivate($id);
    }

    /**
     * @return Product[]
     */
    public function search(string $query, ?string $category = null, int $page = 1, int $size = 20): array
    {
        return $this->products->search(
            trim($query),
            $this->nullIfBlank($category),
            max(1, $page),
            $this->clampSize($size),
        );
    }

    private function validate(string $sku, string $name, int $priceCents, int $stock, ?float $weightKg): void
    {
        $errors = [];

        if ($name === '') {
            $errors['name'] = 'Name is required.';
        }
        if ($sku === '') {
            $errors['sku'] = 'SKU is required.';
        }
        if ($priceCents < 0) {
            $errors['priceCents'] = 'Price must be greater than or equal to 0.';
        }
        if ($stock < 0) {
            $errors['stock'] = 'Stock must be greater than or equal to 0.';
        }
        if ($weightKg !== null && $weightKg < 0) {
            $errors['weightKg'] = 'Weight must be greater than or equal to 0.';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }
    }

    private function skuExists(string $sku): bool
    {
        try {
            $this->products->findBySku($sku);

            return true;
        } catch (ProductNotFoundException) {
            return false;
        }
    }

    private function nullIfBlank(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function clampSize(int $size): int
    {
        return min(self::MAX_PAGE_SIZE, max(1, $size));
    }
}
