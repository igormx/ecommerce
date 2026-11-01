<?php

declare(strict_types=1);

namespace App\Infrastructure\Payment;

use App\Domain\Payment\PaymentGatewayInterface;

/**
 * Fake gateway for development and tests: approves every charge except the
 * magic amount DECLINE_AMOUNT_CENTS.
 */
final class FakePaymentGateway implements PaymentGatewayInterface
{
    /** Charging exactly this amount (6.66) is always declined. */
    public const DECLINE_AMOUNT_CENTS = 666;

    public function charge(int $amountCents): bool
    {
        return $amountCents !== self::DECLINE_AMOUNT_CENTS;
    }
}
