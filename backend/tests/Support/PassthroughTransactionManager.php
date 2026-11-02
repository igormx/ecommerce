<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Persistence\TransactionManagerInterface;

/**
 * Runs the work directly, with no rollback. Fine for tests where the
 * in-memory repositories do not need to model transactional state.
 */
class PassthroughTransactionManager implements TransactionManagerInterface
{
    public function run(callable $work): mixed
    {
        return $work();
    }
}
