<?php

declare(strict_types=1);

namespace App\Domain\Persistence;

interface TransactionManagerInterface
{
    /**
     * Runs $work inside a transaction. Commits when it returns (the return
     * value is passed through); rolls back and rethrows on any Throwable.
     *
     * @param callable(): mixed $work
     */
    public function run(callable $work): mixed;
}
