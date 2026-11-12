<?php

declare(strict_types=1);

namespace Tests\Support\HoldsSqlite;

use Closure;
use LogicException;
use PDOException;

/** Bounded policy for audited SQL steps only, never a business callback or schema replay. */
final class SqliteHoldContention
{
    private array $events = [];

    public function __construct(private readonly int $attempts = 3, private readonly ?Closure $onBusy = null, array $initialEvents = [])
    {
        if ($attempts < 1 || $attempts > 3) {
            throw new LogicException('Only 1..3 contention attempts are supported.');
        }
        $this->events = $initialEvents;
    }

    public static function primary(PDOException $error): int
    {
        $code = $error->errorInfo[1] ?? 0;

        return is_int($code) ? $code & 255 : 0;
    }

    public function record(string $phase, int $attempt, PDOException $error): void
    {
        $this->events[] = ['phase' => $phase, 'attempt' => $attempt, 'code' => $error->errorInfo[1]];
    }

    /** Call sites may retry only connection-local setup, read-only admission, BEGIN or the same COMMIT. */
    public function run(string $phase, callable $step): mixed
    {
        if (! in_array($phase, ['connect', 'admission', 'begin', 'read_begin', 'commit'], true)) {
            throw new LogicException('This phase must not be replayed.');
        }
        for ($attempt = 1; $attempt <= $this->attempts; $attempt++) {
            try {
                return $step();
            } catch (PDOException $error) {
                if (self::primary($error) !== 5) {
                    throw $error;
                }
                $this->record($phase, $attempt, $error);
                if ($attempt === $this->attempts) {
                    throw new SqliteHoldBusy($phase, $attempt);
                }
                if ($this->onBusy !== null) {
                    ($this->onBusy)($phase, $attempt);
                }
                usleep(1000 * $attempt);
            }
        }
        throw new LogicException('Unreachable contention budget.');
    }

    public function events(): array
    {
        return $this->events;
    }
}
