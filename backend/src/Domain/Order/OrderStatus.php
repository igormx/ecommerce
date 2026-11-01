<?php

declare(strict_types=1);

namespace App\Domain\Order;

enum OrderStatus: string
{
    case PAID = 'PAID';
    case FAILED = 'FAILED';
}
