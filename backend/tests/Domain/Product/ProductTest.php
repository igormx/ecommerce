<?php

declare(strict_types=1);

namespace Tests\Domain\Product;

use App\Domain\Product\Product;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class ProductTest extends TestCase
{
    public function testKeepsConstructorValues(): void
    {
        $created = new DateTimeImmutable('2024-01-01');
        $updated = new DateTimeImmutable('2024-01-02');
        $p = new Product(1, 'SKU', 'Name', 'Desc', 'Cat', 1999, 7, 2.5, true, $created, $updated);

        $this->assertSame(1, $p->id);
        $this->assertSame('SKU', $p->sku);
        $this->assertSame('Name', $p->name);
        $this->assertSame('Desc', $p->description);
        $this->assertSame('Cat', $p->category);
        $this->assertSame(1999, $p->priceCents);
        $this->assertSame(7, $p->stock);
        $this->assertSame(2.5, $p->weightKg);
        $this->assertTrue($p->active);
        $this->assertSame($created, $p->createdAt);
        $this->assertSame($updated, $p->updatedAt);
    }

    public function testAllowsNullOptionals(): void
    {
        $now = new DateTimeImmutable();
        $p = new Product(null, 'SKU', 'Name', null, null, 0, 0, null, false, $now, $now);

        $this->assertNull($p->id);
        $this->assertNull($p->description);
        $this->assertNull($p->category);
        $this->assertNull($p->weightKg);
        $this->assertFalse($p->active);
    }
}
