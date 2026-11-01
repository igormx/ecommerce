<?php

declare(strict_types=1);

namespace Tests\Domain\Order;

use App\Domain\DomainException\DomainRecordNotFoundException;
use App\Domain\Order\Exception\OrderNotFoundException;
use PHPUnit\Framework\TestCase;

class OrderNotFoundExceptionTest extends TestCase
{
    public function testForId(): void
    {
        $e = OrderNotFoundException::forId(12);

        $this->assertSame('Order with id 12 not found.', $e->getMessage());
        $this->assertInstanceOf(DomainRecordNotFoundException::class, $e);
    }
}
