<?php

declare(strict_types=1);

namespace Tests\Support;

use PDO;

/** Minimal PDO stand-in that tracks transaction state. */
class TransactionState extends PDO
{
    public bool $active = false;
    public int $begins = 0;
    public int $commits = 0;
    public int $rollbacks = 0;

    public function __construct()
    {
    }

    public function beginTransaction(): bool
    {
        $this->begins++;
        $this->active = true;

        return true;
    }

    public function commit(): bool
    {
        $this->commits++;
        $this->active = false;

        return true;
    }

    public function rollBack(): bool
    {
        $this->rollbacks++;
        $this->active = false;

        return true;
    }

    public function inTransaction(): bool
    {
        return $this->active;
    }
}
