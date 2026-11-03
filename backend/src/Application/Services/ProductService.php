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
    /** Keeps (page - 1) * size far from int overflow in the repository. */
    public const MAX_PAGE = 1_000_000;

    // Limits mirroring the `products` columns (see db/init/01-schema.sql).
    public const MAX_SKU_LENGTH = 32;
    public const MAX_NAME_LENGTH = 255;
    public const MAX_CATEGORY_LENGTH = 100;
    /** TEXT holds 65535 bytes (not characters). */
    public const MAX_DESCRIPTION_BYTES = 65535;
    /** DECIMAL(10,2) UNSIGNED, expressed in cents. */
    public const MAX_PRICE_CENTS = 9_999_999_999;
    /** INT UNSIGNED. */
    public const MAX_STOCK = 4_294_967_295;
    /** DECIMAL(8,3) UNSIGNED. */
    public const MAX_WEIGHT_KG = 99999.999;

    public function __construct(private readonly ProductRepositoryInterface $products)
    {
    }

    /**
     * @return Product[]
     */
    public function list(int $page = 1, int $size = 20): array
    {
        return $this->products->findAll($this->clampPage($page), $this->clampSize($size));
    }

    /**
     * Count of active products, matching list()'s filter.
     */
    public function count(): int
    {
        return $this->products->countAll();
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
        $description = $this->nullIfBlank($description);
        $category = $this->nullIfBlank($category);
        $this->validate($sku, $name, $description, $category, $priceCents, $stock, $weightKg);

        if ($this->skuExists($sku)) {
            throw new DuplicateSkuException($sku);
        }

        $now = new DateTimeImmutable();

        return $this->products->save(new Product(
            null,
            $sku,
            $name,
            $description,
            $category,
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
        $description = $this->nullIfBlank($description);
        $category = $this->nullIfBlank($category);
        $this->validate($sku, $name, $description, $category, $priceCents, $stock, $weightKg);

        if ($sku !== $existing->sku && $this->skuExists($sku)) {
            throw new DuplicateSkuException($sku);
        }

        return $this->products->save(new Product(
            $existing->id,
            $sku,
            $name,
            $description,
            $category,
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
            $this->clampPage($page),
            $this->clampSize($size),
        );
    }

    /**
     * Count of active products matching search()'s filter, same semantics
     * for $query and $category.
     */
    public function countSearch(string $query, ?string $category = null): int
    {
        return $this->products->countSearch(trim($query), $this->nullIfBlank($category));
    }

    private function validate(
        string $sku,
        string $name,
        ?string $description,
        ?string $category,
        int $priceCents,
        int $stock,
        ?float $weightKg,
    ): void {
        $errors = [];

        if ($name === '') {
            $errors['name'] = 'Name is required.';
        } elseif (mb_strlen($name) > self::MAX_NAME_LENGTH) {
            $errors['name'] = 'Name must be at most ' . self::MAX_NAME_LENGTH . ' characters.';
        }
        if ($sku === '') {
            $errors['sku'] = 'SKU is required.';
        } elseif (mb_strlen($sku) > self::MAX_SKU_LENGTH) {
            $errors['sku'] = 'SKU must be at most ' . self::MAX_SKU_LENGTH . ' characters.';
        }
        if ($description !== null && strlen($description) > self::MAX_DESCRIPTION_BYTES) {
            $errors['description'] = 'Description must be at most ' . self::MAX_DESCRIPTION_BYTES . ' bytes.';
        }
        if ($category !== null && mb_strlen($category) > self::MAX_CATEGORY_LENGTH) {
            $errors['category'] = 'Category must be at most ' . self::MAX_CATEGORY_LENGTH . ' characters.';
        }
        if ($priceCents < 0) {
            $errors['priceCents'] = 'Price must be greater than or equal to 0.';
        } elseif ($priceCents > self::MAX_PRICE_CENTS) {
            $errors['priceCents'] = 'Price must be at most ' . self::MAX_PRICE_CENTS . ' cents.';
        }
        if ($stock < 0) {
            $errors['stock'] = 'Stock must be greater than or equal to 0.';
        } elseif ($stock > self::MAX_STOCK) {
            $errors['stock'] = 'Stock must be at most ' . self::MAX_STOCK . '.';
        }
        if ($weightKg !== null) {
            if (is_nan($weightKg) || $weightKg < 0) {
                $errors['weightKg'] = 'Weight must be greater than or equal to 0.';
            } elseif ($weightKg > self::MAX_WEIGHT_KG) {
                $errors['weightKg'] = 'Weight must be at most ' . self::MAX_WEIGHT_KG . '.';
            }
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

    private function clampPage(int $page): int
    {
        return min(self::MAX_PAGE, max(1, $page));
    }

    private function clampSize(int $size): int
    {
        return min(self::MAX_PAGE_SIZE, max(1, $size));
    }
}
