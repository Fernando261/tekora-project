<?php

declare(strict_types=1);

namespace Tests\HoldsSqlitePrototype;

use App\Scheduling\Holds\HoldActor;
use App\Scheduling\Holds\HoldClock;
use App\Scheduling\Holds\HoldEvent;
use App\Scheduling\Holds\HoldReceipt;
use App\Scheduling\Holds\HoldState;
use App\Scheduling\Holds\SlotHold;
use App\Scheduling\Holds\SyntheticHoldEngine;
use DateTimeImmutable;
use LogicException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\HoldsSqlite\HoldCommitUncertain;
use Tests\Support\HoldsSqlite\HoldFixture;
use Tests\Support\HoldsSqlite\SqliteHoldBusy;

#[Group('b222-sqlite-two-connections')]
final class SqliteHoldConcurrencyTest extends TestCase
{
    use HoldFixture;

    public function test_busy_begin_never_runs_decision_or_reads_clock(): void
    {
        $writer = $this->lab->connect();
        $writer->exec('BEGIN IMMEDIATE');
        $clock = new class implements HoldClock
        {
            public int $calls = 0;

            public function now(): DateTimeImmutable
            {
                $this->calls++;

                return new DateTimeImmutable('2030-01-01T08:00:00Z');
            }
        };
        $store = $this->lab->store();
        $engine = new SyntheticHoldEngine($store, $clock);
        try {
            $engine->accept($this->proposal(), HoldActor::provider(110), $this->key(1));
            $this->fail();
        } catch (SqliteHoldBusy $e) {
            $this->assertSame('begin', $e->phase);
            $this->assertSame(3, $e->attempts);
            $this->assertCount(3, $store->busyEvents());
            $this->assertSame(0, $clock->calls);
        } finally {
            $writer->exec('ROLLBACK');
        }
        $this->assertSame([], $this->store->all());
        $this->acquire();
    }

    public function test_clock_sample_after_lock_wait_does_not_consume_hold_duration(): void
    {
        $writer = $this->lab->connect();
        $writer->exec('BEGIN IMMEDIATE');
        $store = $this->lab->store(onBusy: function () use ($writer) {
            $this->advance('+901 seconds');
            $writer->exec('ROLLBACK');
        });
        $hold = (new SyntheticHoldEngine($store, $this->clock))->accept($this->proposal(), HoldActor::provider(110), $this->key(1));
        $this->assertEquals($this->clock->time, $hold->acceptedAt);
        $this->assertEquals($hold->acceptedAt->modify('+900 seconds'), $hold->expiresAt);
        $this->assertCount(1, $store->busyEvents());
    }

    public function test_uncommitted_hold_not_visible_and_second_writer_conflicts_after_commit(): void
    {
        $other = $this->lab->store();
        $p = $this->proposal();
        $h = SlotHold::start($p, $this->clock->time);
        $this->store->atomic(function () use ($other, $p, $h) {
            $this->store->observeClock($this->clock->time);
            $this->store->insert($h, new HoldEvent($h->id, 1, null, HoldState::Held, HoldActor::provider(110), $this->clock->time));
            $this->store->remember(new HoldReceipt('provider:110:'.$this->key(1), hash('sha256', 'accept:'.$p->fingerprint()), $h->id));
            $this->assertNull($other->find($h->id));
            try {
                (new SyntheticHoldEngine($other, $this->clock))->accept($this->proposal(2), HoldActor::provider(110), $this->key(2));
                $this->fail();
            } catch (SqliteHoldBusy $e) {
                $this->assertSame('begin', $e->phase);
            }
        });
        $this->violation('slot_conflict', fn () => (new SyntheticHoldEngine($other, $this->clock))->accept($this->proposal(2), HoldActor::provider(110), $this->key(2)));
        $this->assertCount(1, $this->store->all());
        $this->assertEquals($h, $other->find($h->id));
    }

    public function test_commit_busy_retries_same_commit_without_repeating_body(): void
    {
        $reader = $this->lab->connect();
        $reader->exec('BEGIN');
        $s = $reader->query('SELECT count(*) FROM main.b222_holds');
        $s->fetchColumn();
        $s->closeCursor();
        $calls = 0;
        $store = $this->lab->store(onBusy: static function (string $phase) use ($reader) {
            if ($phase === 'commit') {
                $reader->exec('ROLLBACK');
            }
        });
        $store->atomic(function () use (&$calls, $store) {
            $calls++;
            $store->observeClock($this->clock->time);
        });
        $this->assertSame(1, $calls);
        $this->assertSame('commit', $store->busyEvents()[0]['phase']);
        $this->assertEquals($this->clock->time, $this->store->lastClock());
    }

    public function test_exhausted_commit_busy_rolls_back_all_state_and_allows_retry(): void
    {
        $reader = $this->lab->connect();
        $reader->exec('BEGIN');
        $reader->query('SELECT count(*) FROM main.b222_holds')->fetchColumn();
        $store = $this->lab->store();
        $engine = new SyntheticHoldEngine($store, $this->clock);
        try {
            $engine->accept($this->proposal(), HoldActor::provider(110), $this->key(1));
            $this->fail();
        } catch (SqliteHoldBusy $e) {
            $this->assertSame('commit', $e->phase);
            $this->assertSame(3, $e->attempts);
        } finally {
            $reader->exec('ROLLBACK');
        }
        $this->assertSame([], $this->store->all());
        $this->assertNull($this->store->lastClock());
        $this->assertCount(1, [$engine->accept($this->proposal(), HoldActor::provider(110), $this->key(1))]);
    }

    public function test_lost_acknowledgement_invalidates_connection_and_same_intention_recovers(): void
    {
        $pdo = $this->lab->connect();
        $store = $this->lab->store($pdo, afterCommit: static function () {
            throw new RuntimeException('lost response');
        });
        try {
            (new SyntheticHoldEngine($store, $this->clock))->accept($this->proposal(), HoldActor::provider(110), $this->key(1));
            $this->fail();
        } catch (HoldCommitUncertain) {
            $this->assertFalse($this->lab->owns($pdo));
        }
        try {
            $store->all();
            $this->fail();
        } catch (LogicException) {
            $this->assertTrue(true);
        }
        $before = $this->facts();
        $other = $this->lab->store();
        $engine = new SyntheticHoldEngine($other, $this->clock);
        $first = $other->forReservation(1);
        $recovered = $engine->accept($this->proposal(), HoldActor::provider(110), $this->key(1));
        $this->assertEquals($first, $recovered);
        $this->assertSame($before, $this->facts());
    }

    public function test_failure_before_commit_rolls_back_every_effect(): void
    {
        $before = $this->facts();
        $store = $this->lab->store(beforeCommit: static function () {
            throw new RuntimeException('before commit');
        });
        try {
            (new SyntheticHoldEngine($store, $this->clock))->accept($this->proposal(), HoldActor::provider(110), $this->key(1));
            $this->fail();
        } catch (RuntimeException $e) {
            $this->assertSame('before commit', $e->getMessage());
        }
        $this->assertSame($before, $this->facts($this->lab->connect()));
        $this->acquire();
    }

    public function test_manual_context_loss_is_uncertain_and_cannot_be_readopted(): void
    {
        try {
            $this->store->atomic(function () {
                $this->store->observeClock($this->clock->time);
                $this->pdo->exec('ROLLBACK');
                throw new RuntimeException('context lost');
            });
            $this->fail();
        } catch (HoldCommitUncertain) {
            $this->assertFalse($this->lab->owns($this->pdo));
        }
        $this->assertNull($this->lab->store()->lastClock());
        $this->expectException(LogicException::class);
        $this->lab->store($this->pdo);
    }

    public function test_stale_version_from_another_connection_does_not_relabel_terminal(): void
    {
        $h = $this->acquire();
        $other = new SyntheticHoldEngine($this->lab->store(), $this->clock);
        $released = $this->engine->release($h->id, HoldActor::client(901), 1, $this->key(2));
        $this->violation('invalid_state', fn () => $other->commit($h->id, HoldActor::system(), 1, $this->key(3)));
        $this->assertEquals($released, $this->store->find($h->id));
        $this->assertCount(2, $this->store->history($h->id));
    }
}
