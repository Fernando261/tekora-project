<?php

declare(strict_types=1);

namespace App\Scheduling\Holds;

use DateTimeImmutable;

interface HoldClock
{
    public function now(): DateTimeImmutable;
}
