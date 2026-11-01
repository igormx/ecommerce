<?php

declare(strict_types=1);

namespace App\Application\Services\Exception;

use App\Domain\DomainException\DomainException;

final class ValidationException extends DomainException
{
    /**
     * @param array<string, string> $errors field => message
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('Validation failed: ' . implode(' ', $errors));
    }
}
