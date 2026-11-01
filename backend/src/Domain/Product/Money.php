<?php

declare(strict_types=1);

namespace App\Domain\Product;

use InvalidArgumentException;

/**
 * Conversions between DECIMAL(10,2) strings and integer cents, without floats.
 */
final class Money
{
    /**
     * "12.50" -> 1250, "7" -> 700, "0.5" -> 50.
     */
    public static function decimalToCents(string $decimal): int
    {
        if (preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', trim($decimal), $m) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid decimal amount "%s".', $decimal));
        }

        $fraction = str_pad($m[2] ?? '', 2, '0');

        return ((int) $m[1]) * 100 + (int) $fraction;
    }

    /**
     * 1250 -> "12.50".
     */
    public static function centsToDecimal(int $cents): string
    {
        if ($cents < 0) {
            throw new InvalidArgumentException('Cents must not be negative.');
        }

        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }
}
