<?php

declare(strict_types=1);

namespace Tests\PaymentsIntegrationPrototype;

use App\Scheduling\Holds\HoldActor;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PaymentsIntegration\IntegrationBusy;
use Tests\Support\PaymentsIntegration\IntegrationTestCase;
use Tests\Support\PaymentsIntegration\ScenarioFixture;
use Tests\Support\PaymentsIntegration\SharedSqliteUnitOfWork;
use Tests\Support\PaymentsIntegration\SqliteOutcomePolicy;

#[\PHPUnit\Framework\Attributes\Group('b231-D-errors')]
final class ContentionTest extends IntegrationTestCase
{
    private function intent(): \Tests\Support\PaymentsIntegration\CommandEnvelope
    {
        return ScenarioFixture::intent('probe', HoldActor::system(), $this->key(), []);
    }

    public function test_t28_connect_contention_is_bounded_and_recorded_even_without_pdo(): void
    {
        $lock = $this->lab->connect();
        $lock->exec('BEGIN EXCLUSIVE');
        $policy = new SqliteOutcomePolicy;
        $this->rejects(fn () => new SharedSqliteUnitOfWork($this->lab, fn () => $this->clock->now(), $policy), IntegrationBusy::class);
        self::assertCount(3, $policy->events);
        self::assertSame(['connect'], array_unique(array_column($policy->events, 'phase')));
        $lock->exec('ROLLBACK');
    }

    public function test_t28_admission_contention_preserves_zero_callback_count(): void
    {
        $lock = $this->lab->connect();
        $lock->exec('BEGIN EXCLUSIVE');
        $calls = (object) ['count' => 0];
        $this->rejects(fn () => $this->unit->run($this->intent(), function () use (&$calls): array {
            $calls->count++;

            return ['code' => 'bad'];
        }), IntegrationBusy::class);
        self::assertSame(0, $calls->count);
        self::assertSame('admission', $this->unit->policy->events[0]['phase']);
        $lock->exec('ROLLBACK');
    }

    public function test_t29_begin_can_succeed_after_lock_release_without_replaying_work(): void
    {
        $lock = $this->lab->connect();
        $policy = new SqliteOutcomePolicy(3, function ($phase, $attempt) use ($lock): void {
            if ($phase === 'begin') {
                $lock->exec('ROLLBACK');
            }
        });
        $unit = new SharedSqliteUnitOfWork($this->lab, fn () => $this->clock->now(), $policy);
        $lock->exec('BEGIN IMMEDIATE');
        $calls = (object) ['count' => 0];
        $r = $unit->run($this->intent(), function () use (&$calls): array {
            $calls->count++;

            return ['code' => 'once'];
        });
        $unit->close();
        self::assertSame(1, $calls->count);
        self::assertSame('committed', $r->kind);
        self::assertSame('begin', $policy->events[0]['phase']);
    }

    public function test_t29_begin_exhaustion_never_invokes_callback(): void
    {
        $lock = $this->lab->connect();
        $lock->exec('BEGIN IMMEDIATE');
        $calls = (object) ['count' => 0];
        $this->rejects(fn () => $this->unit->run($this->intent(), function () use (&$calls): array {
            $calls->count++;

            return ['code' => 'bad'];
        }), IntegrationBusy::class);
        $lock->exec('ROLLBACK');
        self::assertSame(0, $calls->count);
        self::assertCount(3, $this->unit->policy->events);
    }

    public function test_t30_active_busy_rolls_back_without_retrying_callback(): void
    {
        $this->proposal();
        $calls = (object) ['count' => 0];
        $f = new ScenarioFixture($this->unit, function ($stage) use (&$calls): void {
            if ($stage === 'head') {
                $calls->count++;
                $e = new \PDOException('synthetic body BUSY');
                $e->errorInfo = ['HY000', 5, 'synthetic'];
                throw $e;
            }
        });
        $this->rejects(fn () => $f->accept(101, 1, 1, $this->key()), IntegrationBusy::class);
        self::assertSame(1, $calls->count);
        self::assertSame(0, $this->rowCount('holds'));
        self::assertSame('body', $this->unit->policy->events[0]['phase']);
    }

    public static function readerModes(): array
    {
        return [[true], [false]];
    }

    #[DataProvider('readerModes')]
    public function test_t31_commit_retries_only_commit_behind_real_reader(bool $release): void
    {
        $this->proposal();
        $reader = $this->lab->connect();
        $reader->exec('BEGIN');
        $reader->query('SELECT * FROM b231_proposals')->fetchAll();
        $calls = (object) ['count' => 0];
        $policy = new SqliteOutcomePolicy(3, function ($phase, $attempt) use ($reader, $release): void {
            if ($phase === 'commit' && $release) {
                $reader->exec('COMMIT');
            }
        });
        $unit = new SharedSqliteUnitOfWork($this->lab, fn () => $this->clock->now(), $policy);
        $f = new ScenarioFixture($unit, function ($stage) use (&$calls): void {
            if ($stage === 'activation') {
                $calls->count++;
            }
        });
        if ($release) {
            self::assertSame('held', $f->accept(101, 1, 1, $this->key())->historical['code']);
            self::assertCount(1, $policy->events);
        } else {
            $this->rejects(fn () => $f->accept(101, 1, 1, $this->key()), IntegrationBusy::class);
            $reader->exec('COMMIT');
            self::assertSame(0, $this->rowCount('holds'));
            self::assertCount(3, $policy->events);
        }
        self::assertSame(1, $calls->count);
        $unit->close();
    }

    public static function otherCodes(): array
    {
        return [[6], [10], [19], [517], ['5']];
    }

    #[DataProvider('otherCodes')]
    public function test_t32_nonordinary_codes_are_not_retried(mixed $code): void
    {
        $policy = new SqliteOutcomePolicy;
        $calls = (object) ['count' => 0];
        $e = new \PDOException('synthetic classification');
        $e->errorInfo = ['HY000', $code, 'synthetic'];
        self::assertSame($e, $this->rejects(fn () => $policy->perform('begin', function () use (&$calls, $e) {
            $calls->count++;
            throw $e;
        }), \PDOException::class));
        self::assertSame(1, $calls->count);
        self::assertSame([], $policy->events);
    }

    public function test_t32_observer_failure_preserves_error_and_rolls_back(): void
    {
        $this->proposal();
        $reader = $this->lab->connect();
        $reader->exec('BEGIN');
        $reader->query('SELECT * FROM b231_quotes')->fetchAll();
        $policy = new SqliteOutcomePolicy(3, static fn () => throw new \DomainException('observer failure'));
        $unit = new SharedSqliteUnitOfWork($this->lab, fn () => $this->clock->now(), $policy);
        $f = new ScenarioFixture($unit);
        $this->rejects(fn () => $f->accept(101, 1, 1, $this->key()), \DomainException::class);
        $reader->exec('COMMIT');
        self::assertSame(0, $this->rowCount('holds'));
        self::assertCount(1, $policy->events);
        $unit->close();
    }

    public function test_commit_busy_crossing_deadline_aborts_without_renewal(): void
    {
        $this->proposal();
        $reader = $this->lab->connect();
        $reader->exec('BEGIN');
        $reader->query('SELECT * FROM b231_quotes')->fetchAll();
        $policy = new SqliteOutcomePolicy(3, function () use ($reader): void {
            $reader->exec('COMMIT');
            $this->clock->us += 900000000;
        });
        $unit = new SharedSqliteUnitOfWork($this->lab, fn () => $this->clock->now(), $policy);
        $f = new ScenarioFixture($unit);
        $this->rejects(fn () => $f->accept(101, 1, 1, $this->key()));
        self::assertSame(0, $this->rowCount('holds'));
        $unit->close();
    }

    public function test_success_returning_after_deadline_does_not_advertise_active_hold(): void
    {
        $this->proposal();
        $unit = new SharedSqliteUnitOfWork($this->lab, fn () => $this->clock->now(), fault: function ($phase): void {
            if ($phase === 'after_commit') {
                $this->clock->us += 900000000;
            }
        });
        $f = new ScenarioFixture($unit);
        $key = $this->key();
        $r = $f->accept(101, 1, 1, $key);
        self::assertTrue($r->pendingTimeResolution);
        self::assertSame(900000000, $r->current['expires_at_us'] - $r->current['accepted_at_us']);
        $unit->close();
        $r = $this->f->accept(101, 1, 1, $key);
        self::assertSame('expired', $r->current['state']);
    }

    public function test_clock_is_read_only_after_successful_begin(): void
    {
        $this->proposal();
        $lock = $this->lab->connect();
        $policy = new SqliteOutcomePolicy(3, function ($phase) use ($lock): void {
            if ($phase === 'begin') {
                $this->clock->us += 1234567;
                $lock->exec('ROLLBACK');
            }
        });
        $unit = new SharedSqliteUnitOfWork($this->lab, fn () => $this->clock->now(), $policy);
        $lock->exec('BEGIN IMMEDIATE');
        $r = (new ScenarioFixture($unit))->accept(101, 1, 1, $this->key());
        self::assertSame($this->clock->us, $r->current['accepted_at_us']);
        $unit->close();
    }

    public function test_clock_regressing_inside_command_aborts_all_effects(): void
    {
        $this->proposal();
        $fixture = new ScenarioFixture($this->unit, function ($stage): void {
            if ($stage === 'head') {
                $this->clock->us--;
            }
        });
        $this->rejects(fn () => $fixture->accept(101, 1, 1, $this->key()));
        self::assertSame(0, $this->rowCount('holds'));
    }
}
