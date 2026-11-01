<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Persistence\TransactionManagerInterface;
use PDO;
use Throwable;

final class PdoTransactionManager implements TransactionManagerInterface
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function run(callable $work): mixed
    {
        // Already inside a transaction: join it, the outer owner commits.
        if ($this->isActive()) {
            return $work();
        }

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

    /**
     * @phpstan-impure The connection state changes between calls.
     */
    private function isActive(): bool
    {
        return $this->pdo->inTransaction();
    }
}
