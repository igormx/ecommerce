<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Payment\PaymentGatewayInterface;

class StubPaymentGateway implements PaymentGatewayInterface
{
    public function __construct(private readonly bool $approve = true)
    {
    }

    public function charge(int $amountCents): bool
    {
        return $this->approve;
    }
}
