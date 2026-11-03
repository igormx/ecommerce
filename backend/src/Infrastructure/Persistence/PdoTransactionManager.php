<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Persistence\Exception\TransientTransactionException;
use App\Domain\Persistence\TransactionManagerInterface;
use Closure;
use PDO;
use PDOException;
use Throwable;

final class PdoTransactionManager implements TransactionManagerInterface
{
    /** MySQL error 1213: deadlock found; 1205: lock wait timeout exceeded. */
    private const TRANSIENT_DRIVER_CODES = [1213, 1205];
    private const TRANSIENT_SQLSTATE = '40001';

    private readonly Closure $sleeper;

    /**
     * @param int $maxAttempts total attempts for an outermost transaction (1 = no retry)
     * @param int $baseBackoffMs upper bound of the jittered wait after the first failure (doubles each time);
     *                           0 disables waiting
     * @param Closure(int): void|null $sleeper receives microseconds to wait; defaults to usleep
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly int $maxAttempts = 3,
        private readonly int $baseBackoffMs = 25,
        ?Closure $sleeper = null,
    ) {
        $this->sleeper = $sleeper ?? static function (int $micros): void {
            usleep($micros);
        };
    }

    public function run(callable $work): mixed
    {
        // Already inside a transaction: join it, the outer owner commits (and retries).
        if ($this->isActive()) {
            return $work();
        }

        $attempts = max(1, $this->maxAttempts);
        for ($attempt = 1;; $attempt++) {
            try {
                return $this->runOnce($work);
            } catch (Throwable $e) {
                if (!self::isTransient($e)) {
                    throw $e;
                }
                if ($attempt >= $attempts) {
                    throw new TransientTransactionException($attempts, $e);
                }
                $this->backoff($attempt);
            }
        }
    }

    /**
     * @param callable(): mixed $work
     */
    private function runOnce(callable $work): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $work();
            $this->pdo->commit();

            return $result;
        } catch (Throwable $e) {
            // A failed commit (or implicit commit) may have already ended it.
            if ($this->isActive()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    private function backoff(int $attempt): void
    {
        if ($this->baseBackoffMs <= 0) {
            return;
        }
        $ceilingMs = $this->baseBackoffMs * (2 ** ($attempt - 1));
        ($this->sleeper)(random_int((int) ($ceilingMs / 2), $ceilingMs) * 1000);
    }

    private static function isTransient(Throwable $e): bool
    {
        for ($cur = $e; $cur !== null; $cur = $cur->getPrevious()) {
            if (!$cur instanceof PDOException) {
                continue;
            }
            $driverCode = $cur->errorInfo[1] ?? null;
            if (is_int($driverCode) && in_array($driverCode, self::TRANSIENT_DRIVER_CODES, true)) {
                return true;
            }
            $sqlState = $cur->errorInfo[0] ?? $cur->getCode();
            if ($sqlState === self::TRANSIENT_SQLSTATE) {
                return true;
            }
        }

        return false;
    }

    /**
     * @phpstan-impure The connection state changes between calls.
     */
    private function isActive(): bool
    {
        return $this->pdo->inTransaction();
    }
}
