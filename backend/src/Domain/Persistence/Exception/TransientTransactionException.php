<?php

declare(strict_types=1);

namespace App\Domain\Persistence\Exception;

use RuntimeException;
use Throwable;

/**
 * A transaction kept failing with a transient database error (deadlock or lock
 * wait timeout) through every retry attempt. Safe for the client to retry.
 */
final class TransientTransactionException extends RuntimeException
{
    public function __construct(int $attempts, ?Throwable $previous = null)
    {
        parent::__construct(
            sprintf('The transaction could not complete after %d attempts due to contention.', $attempts),
            0,
            $previous,
        );
    }
}
