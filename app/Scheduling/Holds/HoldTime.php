<?php

declare(strict_types=1);

namespace App\Scheduling\Holds;

use DateTimeImmutable;
use DateTimeZone;

final class HoldTime
{
    public static function utc(DateTimeImmutable $time): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($time)->setTimezone(new DateTimeZone('UTC'));
    }

    public static function text(DateTimeImmutable $time): string
    {
        return self::utc($time)->format('Y-m-d\TH:i:s.u\Z');
    }
}
