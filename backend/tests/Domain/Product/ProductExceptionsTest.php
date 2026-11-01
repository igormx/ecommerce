<?php

declare(strict_types=1);

namespace Tests\Domain\Product;

use App\Domain\DomainException\DomainException;
use App\Domain\DomainException\DomainRecordNotFoundException;
use App\Domain\Product\Exception\DuplicateSkuException;
use App\Domain\Product\Exception\InsufficientStockException;
use App\Domain\Product\Exception\ProductNotFoundException;
use PHPUnit\Framework\TestCase;

class ProductExceptionsTest extends TestCase
{
    public function testNotFoundForId(): void
    {
        $e = ProductNotFoundException::forId(5);

        $this->assertSame('Product with id 5 not found.', $e->getMessage());
        $this->assertInstanceOf(DomainRecordNotFoundException::class, $e);
    }

    public function testNotFoundForSku(): void
    {
        $this->assertSame('Product with sku "ABC" not found.', ProductNotFoundException::forSku('ABC')->getMessage());
    }

    public function testDuplicateSku(): void
    {
        $e = new DuplicateSkuException('ABC');

        $this->assertSame('ABC', $e->sku);
        $this->assertSame('A product with sku "ABC" already exists.', $e->getMessage());
        $this->assertInstanceOf(DomainException::class, $e);
    }

    public function testInsufficientStock(): void
    {
        $e = new InsufficientStockException(3, 10, 4);

        $this->assertSame(3, $e->productId);
        $this->assertSame(10, $e->requested);
        $this->assertSame(4, $e->available);
        $this->assertSame('Insufficient stock for product 3: requested 10, available 4.', $e->getMessage());
        $this->assertInstanceOf(DomainException::class, $e);
    }
}
