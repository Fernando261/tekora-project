<?php

declare(strict_types=1);

namespace App\Scheduling\Holds;

use DateTimeImmutable;
use DateTimeZone;

final class SystemHoldClock implements HoldClock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
