<?php

declare(strict_types=1);

namespace Tests\Support\PaymentsIntegration;

use DateTimeImmutable;
use Tests\Support\HoldsSqlite\HoldValues as H;

final class FixtureClock
{
    public int $us;

    public function __construct()
    {
        $this->us = H::micros(new DateTimeImmutable('2026-11-12 10:00:00.000000Z'));
    }

    public function now(): DateTimeImmutable
    {
        return H::instant($this->us);
    }
}
