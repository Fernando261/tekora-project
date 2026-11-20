<?php

declare(strict_types=1);

namespace Tests\Support\HoldsSqlite;

use RuntimeException;

final class SqliteHoldBusy extends RuntimeException
{
    public function __construct(public readonly string $phase, public readonly int $attempts)
    {
        parent::__construct('Synthetic SQLite contention: '.$phase);
    }
}
