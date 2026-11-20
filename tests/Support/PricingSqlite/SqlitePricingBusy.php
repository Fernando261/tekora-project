<?php

declare(strict_types=1);

namespace Tests\Support\PricingSqlite;

use RuntimeException;

final class SqlitePricingBusy extends RuntimeException
{
    public function __construct(public readonly string $phase, public readonly int $attempts)
    {
        parent::__construct('Synthetic SQLite busy at '.$phase.' after '.$attempts.' attempts.');
    }
}
