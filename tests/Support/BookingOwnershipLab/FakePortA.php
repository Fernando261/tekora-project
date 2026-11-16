<?php

declare(strict_types=1);

namespace Tests\Support\BookingOwnershipLab;

final class FakePortA
{
    public static function write(SessionLease $lease): void
    {
        $lease->write(ProbeOperation::A);
    }
}
