<?php

declare(strict_types=1);

namespace App\Application\Actions\Product;

use App\Application\Actions\Validation\RequestValidator;
use App\Application\Services\Exception\ValidationException;
use App\Application\Services\ProductService;
use Respect\Validation\Validatable;
use Respect\Validation\Validator as v;

/**
 * `description`, `category` and `weightKg` are nullable; an absent key is
 * treated like an explicit null. `version` is required on update only.
 */
final class ProductInput
{
    private function __construct(
        public readonly string $sku,
        public readonly string $name,
        public readonly ?string $description,
        public readonly ?string $category,
        public readonly int $priceCents,
        public readonly int $stock,
        public readonly ?float $weightKg,
        public readonly ?int $version = null,
    ) {
    }

    /**
     * @return array<string, Validatable>
     */
    public static function rules(): array
    {
        return [
            'sku' => v::stringType()->length(1, ProductService::MAX_SKU_LENGTH),
            'name' => v::stringType()->length(1, ProductService::MAX_NAME_LENGTH),
            'description' => v::nullable(v::stringType()->length(null, ProductService::MAX_DESCRIPTION_BYTES)),
            'category' => v::nullable(v::stringType()->length(null, ProductService::MAX_CATEGORY_LENGTH)),
            'priceCents' => v::intType()->between(0, ProductService::MAX_PRICE_CENTS),
            'stock' => v::intType()->between(0, ProductService::MAX_STOCK),
            'weightKg' => v::nullable(
                v::oneOf(v::intType(), v::floatType())->between(0, ProductService::MAX_WEIGHT_KG)
            ),
        ];
    }

    /**
     * Create fields plus the `version` the client last read.
     *
     * @param array<array-key, mixed> $body
     * @throws ValidationException
     */
    public static function fromUpdateBody(array $body): self
    {
        return self::fromBody($body, true);
    }

    /**
     * @param array<array-key, mixed> $body
     * @throws ValidationException
     */
    public static function fromBody(array $body, bool $withVersion = false): self
    {
        // The length limits apply to the trimmed value, which is what gets stored.
        foreach (['sku', 'name'] as $field) {
            if (isset($body[$field]) && is_string($body[$field])) {
                $body[$field] = trim($body[$field]);
            }
        }

        $rules = self::rules();
        if ($withVersion) {
            $rules['version'] = v::intType()->between(1, ProductService::MAX_VERSION);
        }
        $data = (new RequestValidator())->validate($body, $rules);

        return new self(
            $data['sku'],
            $data['name'],
            $data['description'] ?? null,
            $data['category'] ?? null,
            $data['priceCents'],
            $data['stock'],
            isset($data['weightKg']) ? (float) $data['weightKg'] : null,
            $withVersion ? $data['version'] : null,
        );
    }
}
