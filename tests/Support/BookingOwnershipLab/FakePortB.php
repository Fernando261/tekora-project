<?php

declare(strict_types=1);

namespace Tests\Support\BookingOwnershipLab;

final class FakePortB
{
    public static function write(SessionLease $lease, int $value = 22): void
    {
        $lease->write(ProbeOperation::B, $value);
    }
}
