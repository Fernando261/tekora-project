<?php

declare(strict_types=1);

namespace Tests\Support\PaymentsIntegration;

use Closure;
use PDOException;
use Throwable;

/** Only ordinary BUSY in the audited DELETE profile is retryable. */
final class SqliteOutcomePolicy
{
    public array $events = [];

    public function __construct(public readonly int $attempts = 3, private readonly ?Closure $observer = null)
    {
        if ($attempts < 1 || $attempts > 3) {
            throw new \LogicException('Finite attempt budget required');
        }
    }

    public static function code(Throwable $e): ?int
    {
        return $e instanceof PDOException && is_int($e->errorInfo[1] ?? null) ? $e->errorInfo[1] : null;
    }

    public function record(string $phase, int $attempt, Throwable $e): void
    {
        $this->events[] = ['phase' => $phase, 'attempt' => $attempt, 'code' => self::code($e)];
    }

    public function perform(string $phase, Closure $step): mixed
    {
        if (! in_array($phase, ['connect', 'admission', 'begin', 'commit'], true)) {
            throw new \LogicException('Unaudited retry phase');
        }
        for ($i = 1; $i <= $this->attempts; $i++) {
            try {
                return $step();
            } catch (Throwable $e) {
                if (self::code($e) !== 5) {
                    throw $e;
                }
                $this->record($phase, $i, $e);
                if ($i === $this->attempts) {
                    throw new IntegrationBusy('SQLite contention: '.$phase, previous: $e);
                }
                if ($this->observer !== null) {
                    ($this->observer)($phase, $i);
                }
                usleep($i * 1000);
            }
        }
        throw new \LogicException('Unreachable');
    }
}
