<?php

declare(strict_types=1);

namespace Tests\HoldsSqlitePrototype;

use App\Scheduling\Holds\HoldActor;
use App\Scheduling\Holds\SyntheticHoldEngine;
use LogicException;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\HoldsSqlite\HoldCommitUncertain;
use Tests\Support\HoldsSqlite\HoldFixture;
use Tests\Support\HoldsSqlite\SqliteHoldBusy;
use Tests\Support\HoldsSqlite\SqliteHoldContention;

#[Group('b222-fix1')]
final class SqliteHoldAdmissionTest extends TestCase
{
    use HoldFixture;

    public static function budgets(): array
    {
        return [[1], [2], [3]];
    }

    #[DataProvider('budgets')]
    public function test_admission_busy_is_bounded_recorded_and_never_runs_business_body(int $budget): void
    {
        $blocker = $this->lab->connect();
        $pdo = $this->lab->connect();
        $callbacks = 0;
        $observers = 0;
        $store = $this->lab->store($pdo, attempts: $budget, onBusy: static function () use (&$observers) {
            $observers++;
        });
        $before = $this->facts();
        $blocker->exec('BEGIN EXCLUSIVE');
        try {
            $store->atomic(static function () use (&$callbacks) {
                $callbacks++;
            });
            $this->fail('Admission budget must exhaust');
        } catch (SqliteHoldBusy $error) {
            $this->assertSame('admission', $error->phase);
            $this->assertSame($budget, $error->attempts);
        } finally {
            $blocker->exec('ROLLBACK');
        }
        $this->assertSame(0, $callbacks);
        $this->assertSame($budget - 1, $observers);
        $this->assertCount($budget, $store->busyEvents());
        foreach ($store->busyEvents() as $i => $event) {
            $this->assertSame(['phase' => 'admission', 'attempt' => $i + 1, 'code' => 5], $event);
        }
        $this->assertSame($before, $this->facts($this->lab->connect()));
        $store->atomic(static function () use (&$callbacks) {
            $callbacks++;
        });
        $this->assertSame(1, $callbacks);
    }

    public function test_release_during_admission_allows_one_callback_and_original_hold_deadline(): void
    {
        $blocker = $this->lab->connect();
        $pdo = $this->lab->connect();
        $observers = 0;
        $store = $this->lab->store($pdo, onBusy: static function (string $phase, int $attempt) use ($blocker, &$observers) {
            $observers++;
            if ($phase !== 'admission' || $attempt !== 1) {
                throw new RuntimeException('Wrong preparation phase');
            }$blocker->exec('ROLLBACK');
        });
        $blocker->exec('BEGIN EXCLUSIVE');
        try {
            $hold = (new SyntheticHoldEngine($store, $this->clock))->accept($this->proposal(), HoldActor::provider(110), $this->key(1));
        } finally {
            if ($blocker->inTransaction()) {
                $blocker->exec('ROLLBACK');
            }
        }
        $this->assertSame(1, $observers);
        $this->assertCount(1, $store->busyEvents());
        $this->assertSame('admission', $store->busyEvents()[0]['phase']);
        $this->assertEquals($this->clock->time, $hold->acceptedAt);
        $this->assertEquals($hold->acceptedAt->modify('+900 seconds'), $hold->expiresAt);
        $this->assertCount(1, $this->lab->store()->all());
        $this->assertCount(1, $this->lab->store()->history($hold->id));
    }

    #[DataProvider('budgets')]
    public function test_new_connection_exhaustion_is_controlled_and_recorded_without_registered_pdo(int $budget): void
    {
        $blocker = $this->lab->connect();
        $before = $this->facts();
        $calls = 0;
        $offset = count($this->lab->busyEvents());
        $blocker->exec('BEGIN EXCLUSIVE');
        try {
            $this->lab->connect($budget, static function () use (&$calls) {
                $calls++;
            });
            $this->fail('Connection budget must exhaust');
        } catch (SqliteHoldBusy $error) {
            $this->assertSame('connect', $error->phase);
            $this->assertSame($budget, $error->attempts);
        } finally {
            $blocker->exec('ROLLBACK');
        }
        $events = array_slice($this->lab->busyEvents(), $offset);
        $this->assertCount($budget, $events);
        $this->assertSame($budget - 1, $calls);
        foreach ($events as $i => $event) {
            $this->assertSame(['phase' => 'connect', 'attempt' => $i + 1, 'code' => 5], $event);
        }
        $this->assertSame($before, $this->facts($this->lab->connect()));
    }

    public function test_successful_connection_retries_are_visible_in_the_resulting_store(): void
    {
        $blocker = $this->lab->connect();
        $calls = 0;
        $blocker->exec('BEGIN EXCLUSIVE');
        try {
            $store = $this->lab->store(onBusy: static function (string $phase, int $attempt) use ($blocker, &$calls) {
                $calls++;
                if ($phase !== 'connect' || $attempt !== 1) {
                    throw new RuntimeException('Wrong connection phase');
                }$blocker->exec('ROLLBACK');
            });
        } finally {
            if ($blocker->inTransaction()) {
                $blocker->exec('ROLLBACK');
            }
        }
        $this->assertSame(1, $calls);
        $this->assertSame([['phase' => 'connect', 'attempt' => 1, 'code' => 5]], $store->busyEvents());
        $hold = (new SyntheticHoldEngine($store, $this->clock))->accept($this->proposal(), HoldActor::provider(110), $this->key(1));
        $this->assertEquals($hold, $this->lab->store()->find($hold->id));
        $this->assertCount(1, $this->lab->store()->all());
    }

    public function test_pending_commit_from_the_real_store_exposes_a_controlled_admission_result(): void
    {
        $reader = $this->lab->connect();
        $competitor = $this->lab->store(attempts: 2);
        $competitorCalls = 0;
        $observed = null;
        $reader->exec('BEGIN');
        $statement = $reader->query('SELECT count(*) FROM main.b222_holds');
        $statement->fetchColumn();
        $statement->closeCursor();
        $writer = $this->lab->store(onBusy: function (string $phase, int $attempt) use ($reader, $competitor, &$competitorCalls, &$observed) {
            $this->assertSame('commit', $phase);
            $this->assertSame(1, $attempt);
            try {
                $competitor->atomic(static function () use (&$competitorCalls) {
                    $competitorCalls++;
                });
                $this->fail('Pending writer must block the competitor');
            } catch (SqliteHoldBusy $error) {
                $observed = $error->phase;
                $this->assertSame(2, $error->attempts);
            } finally {
                $reader->exec('ROLLBACK');
            }
        });
        $hold = (new SyntheticHoldEngine($writer, $this->clock))->accept($this->proposal(), HoldActor::provider(110), $this->key(1));
        $this->assertSame('admission', $observed);
        $this->assertSame(0, $competitorCalls);
        $this->assertCount(2, $competitor->busyEvents());
        $this->assertSame('commit', $writer->busyEvents()[0]['phase']);
        $competitor->atomic(static function () use (&$competitorCalls) {
            $competitorCalls++;
        });
        $this->assertSame(1, $competitorCalls);
        $third = $this->lab->store();
        $this->assertEquals($hold, $third->find($hold->id));
        $this->assertCount(1, $third->all());
    }

    public static function structuralCodes(): array
    {
        return [[1], [6], [10], [19], [0]];
    }

    #[DataProvider('structuralCodes')]
    public function test_policy_never_retries_or_relabels_non_busy_driver_errors(int $code): void
    {
        $error = new PDOException('injected non-BUSY error');
        $error->errorInfo = ['HY000', $code, 'synthetic'];
        $calls = 0;
        $observer = 0;
        $policy = new SqliteHoldContention(3, static function () use (&$observer) {
            $observer++;
        });
        try {
            $policy->run('admission', static function () use ($error, &$calls) {
                $calls++;
                throw $error;
            });
            $this->fail();
        } catch (PDOException $caught) {
            $this->assertSame($error, $caught);
        }
        $this->assertSame(1, $calls);
        $this->assertSame(0, $observer);
        $this->assertSame([], $policy->events());
    }

    public function test_structural_admission_failure_is_not_masked_by_contention(): void
    {
        $this->pdo->exec('PRAGMA user_version=223');
        $calls = 0;
        try {
            $this->store->atomic(static function () use (&$calls) {
                $calls++;
            });
            $this->fail();
        } catch (LogicException $error) {
            $this->assertSame('Unsupported lab configuration.', $error->getMessage());
        } finally {
            $this->pdo->exec('PRAGMA user_version=222');
        }
        $this->assertSame(0, $calls);
        $this->assertSame([], $this->store->busyEvents());
        $this->assertSame([], $this->store->all());
    }

    public function test_observer_exception_is_not_retried_or_converted_to_busy(): void
    {
        $blocker = $this->lab->connect();
        $pdo = $this->lab->connect();
        $calls = 0;
        $fault = new RuntimeException('observer failure');
        $store = $this->lab->store($pdo, onBusy: static function () use (&$calls, $fault) {
            $calls++;
            throw $fault;
        });
        $blocker->exec('BEGIN EXCLUSIVE');
        try {
            $store->atomic(static fn () => null);
            $this->fail();
        } catch (RuntimeException $error) {
            $this->assertSame($fault, $error);
        } finally {
            $blocker->exec('ROLLBACK');
        }
        $this->assertSame(1, $calls);
        $this->assertCount(1, $store->busyEvents());
        $this->assertSame([], $store->all());
    }

    public function test_busy_inside_an_active_body_rolls_back_without_replaying_it(): void
    {
        $calls = 0;
        $error = new PDOException('injected BUSY in active body');
        $error->errorInfo = ['HY000', 5, 'synthetic'];
        try {
            $this->store->atomic(function () use (&$calls, $error) {
                $calls++;
                $this->store->observeClock($this->clock->time);
                throw $error;
            });
            $this->fail();
        } catch (SqliteHoldBusy $busy) {
            $this->assertSame('body', $busy->phase);
            $this->assertSame(1, $busy->attempts);
        }
        $this->assertSame(1, $calls);
        $this->assertNull($this->lab->store()->lastClock());
        $this->assertSame([['phase' => 'body', 'attempt' => 1, 'code' => 5]], $this->store->busyEvents());
    }

    public function test_busy_code_after_confirmed_commit_remains_uncertain_and_is_never_retried(): void
    {
        $pdo = $this->lab->connect();
        $hooks = 0;
        $error = new PDOException('injected lost acknowledgement');
        $error->errorInfo = ['HY000', 5, 'synthetic'];
        $store = $this->lab->store($pdo, afterCommit: static function () use (&$hooks, $error) {
            $hooks++;
            throw $error;
        });
        try {
            (new SyntheticHoldEngine($store, $this->clock))->accept($this->proposal(), HoldActor::provider(110), $this->key(1));
            $this->fail();
        } catch (HoldCommitUncertain) {
            $this->assertFalse($this->lab->owns($pdo));
        }
        $this->assertSame(1, $hooks);
        $this->assertSame([], $store->busyEvents());
        $reader = $this->lab->store();
        $hold = $reader->forReservation(1);
        $this->assertNotNull($hold);
        $replay = (new SyntheticHoldEngine($reader, $this->clock))->accept($this->proposal(), HoldActor::provider(110), $this->key(1));
        $this->assertSame($hold->id, $replay->id);
        $this->assertEquals($hold->expiresAt, $replay->expiresAt);
        $this->assertCount(1, $reader->all());
        $this->assertCount(1, $reader->history($hold->id));
    }
}
