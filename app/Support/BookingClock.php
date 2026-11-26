<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use LogicException;
use PDO;

/** Server UTC clock shared by the database constraints and reservation commands. */
final class BookingClock
{
    public const HOLD_MICROSECONDS = 900_000_000;

    public static function micros(?Connection $connection = null): int
    {
        $connection ??= DB::connection();
        if ($connection->getDriverName() === 'mysql') {
            $value = $connection->selectOne('SELECT tekora_booking_now_us() AS micros')->micros;
            if ((! is_int($value) && ! is_string($value)) || ! preg_match('/^[1-9][0-9]{9,18}$/D', (string) $value)
                || (string) (int) $value !== (string) $value) {
                throw new LogicException('El reloj InnoDB no devolvió microsegundos enteros.');
            }

            return (int) $value;
        }

        return self::localMicros();
    }

    private static function localMicros(): int
    {
        $now = CarbonImmutable::now('UTC');

        return $now->getTimestamp() * 1_000_000 + (int) $now->format('u');
    }

    public static function observedMicros(): int
    {
        return max(self::micros(), (int) DB::table('reservation_hold_clock')->where('id', 1)->value('high_water_us'));
    }

    public static function observedSql(): string
    {
        return DB::connection()->getDriverName() === 'mysql'
            ? 'GREATEST(tekora_booking_now_us(), (SELECT high_water_us FROM reservation_hold_clock WHERE id=1))'
            : 'max(CAST(tekora_booking_now_us() AS INTEGER), (SELECT high_water_us FROM reservation_hold_clock WHERE id=1))';
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
        $pdo->sqliteCreateFunction('tekora_booking_now_us', static fn (): string => (string) self::localMicros(), 0);
    }
}
