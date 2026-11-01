<?php

declare(strict_types=1);

namespace App\Domain\Product\Exception;

use App\Domain\DomainException\DomainException;

final class DuplicateSkuException extends DomainException
{
    public function __construct(public readonly string $sku)
    {
        parent::__construct(sprintf('A product with sku "%s" already exists.', $sku));
    }
}
