<?php

declare(strict_types=1);

namespace App\Domain\Payment;

/**
 * Domain service abstraction for charging a payment. Money is integer cents.
 */
interface PaymentGatewayInterface
{
    /**
     * Attempts to charge the given amount.
     *
     * @param int $amountCents Amount to charge, in integer cents.
     * @return bool True if the charge was approved, false if declined.
     */
    public function charge(int $amountCents): bool;
}
