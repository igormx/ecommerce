<?php

declare(strict_types=1);

namespace App\Domain\Payment;

/**
 * Money is integer cents.
 */
interface PaymentGatewayInterface
{
    /**
     * @return bool true if approved
     */
    public function charge(int $amountCents): bool;
}
