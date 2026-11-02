<?php

declare(strict_types=1);

namespace App\Application\Services\Exception;

use App\Domain\DomainException\DomainException;

final class InvalidPurchaseException extends DomainException
{
    public static function invalidQuantity(int $productId, int $quantity): self
    {
        return new self(sprintf('Invalid quantity %d for product %d: must be greater than 0.', $quantity, $productId));
    }

    public static function tooManyLines(int $max): self
    {
        return new self(sprintf('A purchase can contain at most %d lines.', $max));
    }

    public static function nonIntegerLine(): self
    {
        return new self('Product ids and quantities must be integers.');
    }

    public static function emptyPurchase(): self
    {
        return new self('A purchase must contain at least one line.');
    }
}
