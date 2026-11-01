<?php

declare(strict_types=1);

namespace App\Domain\Product\Exception;

use App\Domain\DomainException\DomainRecordNotFoundException;

final class ProductNotFoundException extends DomainRecordNotFoundException
{
    public static function forId(int $id): self
    {
        return new self(sprintf('Product with id %d not found.', $id));
    }

    public static function forSku(string $sku): self
    {
        return new self(sprintf('Product with sku "%s" not found.', $sku));
    }
}
