<?php

declare(strict_types=1);

namespace App\Application\Services\Exception;

use RuntimeException;

/**
 * Internal signal used by PurchaseService to roll back the stock decrement
 * when the payment gateway declines. Never escapes PurchaseService::purchase().
 */
final class PaymentDeclinedRollback extends RuntimeException
{
}
