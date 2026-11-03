<?php

declare(strict_types=1);

namespace App\Domain\Product\Exception;

use App\Domain\DomainException\DomainException;

/**
 * The product changed (edit or purchase) after the client read it, so its
 * version no longer matches. The client must reload and retry.
 */
final class StaleProductException extends DomainException
{
    public function __construct(public readonly int $productId)
    {
        parent::__construct('Product was modified by someone else; reload and try again.');
    }
}
