<?php

declare(strict_types=1);

namespace Tests\Application\Services\Exception;

use App\Application\Services\Exception\InvalidPurchaseException;
use App\Application\Services\Exception\ValidationException;
use App\Domain\DomainException\DomainException;
use PHPUnit\Framework\TestCase;

class ServiceExceptionsTest extends TestCase
{
    public function testInvalidQuantity(): void
    {
        $e = InvalidPurchaseException::invalidQuantity(4, 0);

        $this->assertSame('Invalid quantity 0 for product 4: must be greater than 0.', $e->getMessage());
        $this->assertInstanceOf(DomainException::class, $e);
    }

    public function testNonIntegerLine(): void
    {
        $this->assertSame(
            'Product ids and quantities must be integers.',
            InvalidPurchaseException::nonIntegerLine()->getMessage()
        );
    }

    public function testEmptyPurchase(): void
    {
        $this->assertSame(
            'A purchase must contain at least one line.',
            InvalidPurchaseException::emptyPurchase()->getMessage()
        );
    }

    public function testValidationExceptionExposesErrors(): void
    {
        $errors = ['sku' => 'sku is required.', 'price' => 'price must be positive.'];
        $e = new ValidationException($errors);

        $this->assertSame($errors, $e->errors);
        $this->assertSame('Validation failed: sku is required. price must be positive.', $e->getMessage());
        $this->assertInstanceOf(DomainException::class, $e);
    }
}
