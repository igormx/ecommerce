<?php

declare(strict_types=1);

namespace Tests\Infrastructure\Payment;

use App\Infrastructure\Payment\FakePaymentGateway;
use Tests\TestCase;

class FakePaymentGatewayTest extends TestCase
{
    public function testApprovesNormalAmounts(): void
    {
        $gateway = new FakePaymentGateway();

        $this->assertTrue($gateway->charge(1));
        $this->assertTrue($gateway->charge(10000));
    }

    public function testDeclinesMagicAmount(): void
    {
        $gateway = new FakePaymentGateway();

        $this->assertFalse($gateway->charge(FakePaymentGateway::DECLINE_AMOUNT_CENTS));
    }
}
