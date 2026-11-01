<?php

declare(strict_types=1);

namespace App\Domain\Product\Exception;

use App\Domain\DomainException\DomainException;

final class InsufficientStockException extends DomainException
{
    public function __construct(
        public readonly int $productId,
        public readonly int $requested,
        public readonly int $available,
    ) {
        parent::__construct(sprintf(
            'Insufficient stock for product %d: requested %d, available %d.',
            $productId,
            $requested,
            $available,
        ));
    }
}
