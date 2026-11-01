<?php

declare(strict_types=1);

namespace Tests\Domain\Product;

use App\Domain\Product\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    /**
     * @return array<string, array{string, int}>
     */
    public function decimalProvider(): array
    {
        return [
            'two decimals' => ['12.50', 1250],
            'integer' => ['7', 700],
            'one decimal' => ['0.5', 50],
            'zero' => ['0.00', 0],
            'float trap' => ['19.99', 1999],
            'another float trap' => ['1.15', 115],
            'large' => ['99999999.99', 9999999999],
        ];
    }

    /**
     * @dataProvider decimalProvider
     */
    public function testDecimalToCents(string $decimal, int $cents): void
    {
        $this->assertSame($cents, Money::decimalToCents($decimal));
    }

    public function testInvalidDecimalThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::decimalToCents('1.234');
    }

    public function testNegativeDecimalThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::decimalToCents('-1.00');
    }

    public function testCentsToDecimal(): void
    {
        $this->assertSame('12.50', Money::centsToDecimal(1250));
        $this->assertSame('0.05', Money::centsToDecimal(5));
        $this->assertSame('0.00', Money::centsToDecimal(0));
        $this->assertSame('19.99', Money::centsToDecimal(1999));
    }

    public function testNegativeCentsThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::centsToDecimal(-1);
    }
}
