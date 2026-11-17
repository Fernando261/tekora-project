<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use PDO;

/** Server UTC clock shared by SQLite constraints and reservation commands. */
final class BookingClock
{
    public const HOLD_MICROSECONDS = 900_000_000;

    public static function micros(): int
    {
        $now = CarbonImmutable::now('UTC');

        return $now->getTimestamp() * 1_000_000 + (int) $now->format('u');
    }

    public static function observedMicros(): int
    {
        return max(self::micros(), (int) \Illuminate\Support\Facades\DB::table('reservation_hold_clock')->where('id', 1)->value('high_water_us'));
    }

    public static function date(int $micros): CarbonImmutable
    {
        return CarbonImmutable::createFromTimestampUTC(intdiv($micros, 1_000_000))->addMicroseconds($micros % 1_000_000);
    }

    public static function install(PDO $pdo): void
    {
        if (PHP_INT_SIZE !== 8 || $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            throw new \LogicException('Reservas requiere SQLite/PHP 64 bits.');
        }
        // PDO SQLite truncates integer UDF results to 32 bits on supported PHP builds.
        // Return decimal text and CAST to SQLite INTEGER at every schema use.
        $pdo->sqliteCreateFunction('tekora_booking_now_us', static fn (): string => (string) self::micros(), 0);
    }
}
