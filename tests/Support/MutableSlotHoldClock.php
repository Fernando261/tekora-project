<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Scheduling\Holds\HoldClock;
use DateTimeImmutable;

final class MutableSlotHoldClock implements HoldClock
{
    public function __construct(public DateTimeImmutable $time) {}

    public function now(): DateTimeImmutable
    {
        return $this->time;
    }
}
