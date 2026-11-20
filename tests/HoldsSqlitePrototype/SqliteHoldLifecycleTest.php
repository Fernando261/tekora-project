<?php

declare(strict_types=1);

namespace Tests\HoldsSqlitePrototype;

use App\Scheduling\Holds\HoldActor;
use App\Scheduling\Holds\HoldEvent;
use App\Scheduling\Holds\HoldState;
use App\Scheduling\Holds\SyntheticHoldEngine;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\HoldsSqlite\HoldFixture;
use Tests\Support\HoldsSqlite\HoldValues;

#[Group('b222-sqlite-single')]
final class SqliteHoldLifecycleTest extends TestCase
{
    use HoldFixture;

    public function test_concrete_proposal_and_reconnection_preserve_every_value_and_history(): void
    {
        $hold = $this->acquire();
        $reader = $this->lab->store();
        $this->assertEquals($hold, $reader->find($hold->id));
        $this->assertNotSame($hold, $reader->find($hold->id));
        $this->assertSame(900000000, HoldValues::micros($hold->expiresAt) - HoldValues::micros($hold->acceptedAt));
        $this->assertSame(3600000000, HoldValues::micros($hold->proposal->interval->end) - HoldValues::micros($hold->proposal->interval->start));
        $this->assertEquals($hold, $reader->forReservation(1));
        $this->assertEquals($hold, $reader->forQuote('quote-1'));
        $this->assertEquals([$hold], $reader->forProvider(10));
        $this->assertCount(1, $reader->history($hold->id));
        $this->assertEquals($hold->acceptedAt, $reader->lastClock());
    }

    public static function intervals(): array
    {
        return [['10:00:00.000001', 10, true], ['09:30:00.000001', 10, true], ['10:59:59.999999', 10, true], ['09:00:00.000001', 10, false], ['11:00:00.000001', 10, false], ['10:00:00.000001', 20, false]];
    }

    #[DataProvider('intervals')]
    public function test_capacity_is_shared_across_services_and_consecutive_intervals_allowed(string $time, int $provider, bool $conflict): void
    {
        $this->acquire();
        $p = $this->proposal(2, $time, $provider);
        if ($conflict) {
            $this->violation('slot_conflict', fn () => $this->engine->accept($p, HoldActor::provider($provider + 100), $this->key(2)));
        } else {
            $this->engine->accept($p, HoldActor::provider($provider + 100), $this->key(2));
            $this->assertCount(2, $this->store->all());
        }
    }

    public static function deadlines(): array
    {
        return [['+899 seconds', false], ['+899 seconds +999999 microseconds', false], ['+900 seconds', true], ['+900 seconds +1 microsecond', true]];
    }

    #[DataProvider('deadlines')]
    public function test_expiration_has_exact_microsecond_boundary(string $elapsed, bool $expired): void
    {
        $h = $this->acquire();
        $this->advance($elapsed);
        $e = new SyntheticHoldEngine($this->lab->store(), $this->clock);
        $replay = $e->accept($this->proposal(), HoldActor::provider(110), $this->key(1));
        $this->assertSame($h->id, $replay->id);
        $this->assertEquals($h->expiresAt, $replay->expiresAt);
        $this->assertSame($expired ? HoldState::Expired : HoldState::Held, $replay->state);
        $this->assertCount($expired ? 2 : 1, $this->store->history($h->id));
    }

    public static function transitions(): array
    {
        return [['release'], ['commit'], ['expire']];
    }

    #[DataProvider('transitions')]
    public function test_terminal_transitions_are_persistent_and_cannot_reactivate(string $command): void
    {
        $h = $this->acquire();
        $next = match ($command) {
            'release' => $this->engine->release($h->id, HoldActor::client(901), 1, $this->key(2)),'commit' => $this->engine->commit($h->id, HoldActor::system(), 1, $this->key(2)),default => (function () {
                $this->advance('+900 seconds');

                return $this->engine->expireDue(HoldActor::system())[0];
            })()
        };
        $this->assertEquals($next, $this->lab->store()->find($h->id));
        $this->assertCount(2, $this->store->history($h->id));
        $this->violation('invalid_state', fn () => $this->engine->release($h->id, HoldActor::provider(110), 2, $this->key(3)));
        $this->violation('reservation_already_bound', fn () => $this->engine->accept($this->proposal(), HoldActor::provider(110), $this->key(4)));
        if ($command === 'commit') {
            $this->violation('slot_conflict', fn () => $this->engine->accept($this->proposal(2), HoldActor::provider(110), $this->key(5)));
        } else {
            $this->engine->accept($this->proposal(2), HoldActor::provider(110), $this->key(5));
            $this->assertCount(2, $this->store->all());
        }
    }

    public function test_retries_and_conflicts_never_renew_deadline_or_replace_intention(): void
    {
        $h = $this->acquire();
        $this->advance('+400 seconds');
        $this->assertEquals($h, $this->engine->accept($this->proposal(), HoldActor::provider(110), strtoupper($this->key(1))));
        $this->violation('idempotency_conflict', fn () => $this->engine->accept($this->proposal(2, '11:00:00.000001'), HoldActor::provider(110), $this->key(1)));
        $this->violation('version_conflict', fn () => $this->engine->release($h->id, HoldActor::client(901), 2, $this->key(3)));
        $released = $this->engine->release($h->id, HoldActor::client(901), 1, $this->key(3));
        $this->assertEquals($released, $this->engine->release($h->id, HoldActor::client(901), 1, $this->key(3)));
        $this->assertCount(2, $this->store->history($h->id));
    }

    public function test_late_conversion_commits_expiry_even_though_command_is_rejected(): void
    {
        $h = $this->acquire();
        $this->advance('+900 seconds');
        $this->violation('invalid_state', fn () => $this->engine->commit($h->id, HoldActor::system(), 1, $this->key(2)));
        $this->assertSame(HoldState::Expired, $this->lab->store()->find($h->id)->state);
        $new = $this->engine->accept($this->proposal(2), HoldActor::provider(110), $this->key(3));
        $this->violation('invalid_state', fn () => $this->engine->commit($h->id, HoldActor::system(), 1, $this->key(4)));
        $this->assertEquals($new, $this->store->find($new->id));
        $this->assertCount(2, $this->store->history($h->id));
    }

    public function test_commit_never_expires_and_expire_due_is_idempotent(): void
    {
        $h = $this->acquire();
        $c = $this->engine->commit($h->id, HoldActor::system(), 1, $this->key(2));
        $this->advance('+1 day');
        $this->assertSame([], $this->engine->expireDue(HoldActor::system()));
        $this->assertEquals($c, $this->store->find($h->id));
        $this->violation('slot_conflict', fn () => $this->engine->accept($this->proposal(2), HoldActor::provider(110), $this->key(3)));
    }

    public function test_authority_and_initial_inquiry_do_not_create_or_modify_holds(): void
    {
        $this->proposal();
        $this->assertSame([], $this->store->all());
        $this->violation('unauthorized', fn () => $this->engine->accept($this->proposal(), HoldActor::client(901), $this->key(1)));
        $h = $this->acquire();
        $this->violation('unauthorized', fn () => $this->engine->release($h->id, HoldActor::client(902), 1, $this->key(2)));
        $this->violation('unauthorized', fn () => $this->engine->commit($h->id, HoldActor::provider(110), 1, $this->key(3)));
    }

    public function test_structural_failure_rolls_back_snapshot_event_receipt_identity_and_clock(): void
    {
        $before = $this->facts();
        try {
            $this->store->atomic(function () {
                $this->store->observeClock($this->clock->time);
                $h = \App\Scheduling\Holds\SlotHold::start($this->proposal(), $this->clock->time);
                $this->store->insert($h, new HoldEvent($h->id, 1, null, HoldState::Held, HoldActor::provider(110), $this->clock->time));
                throw new LogicException('injected');
            });
            $this->fail();
        } catch (LogicException $e) {
            $this->assertSame('injected', $e->getMessage());
        }
        $this->assertSame($before, $this->facts($this->lab->connect()));
        $this->acquire();
    }

    public function test_slot_rejection_can_preserve_other_legitimate_expirations(): void
    {
        $first = $this->acquire();
        $second = $this->engine->accept($this->proposal(2, '11:00:00.000001'), HoldActor::provider(110), $this->key(2));
        $this->engine->commit($second->id, HoldActor::system(), 1, $this->key(3));
        $this->advance('+900 seconds');
        $this->violation('slot_conflict', fn () => $this->engine->accept($this->proposal(3, '11:00:00.000001'), HoldActor::provider(110), $this->key(4)));
        $this->assertSame(HoldState::Expired, $this->lab->store()->find($first->id)->state);
        $this->assertCount(2, $this->store->history($first->id));
        $this->assertCount(2, $this->store->all());
    }

    public function test_structural_failure_rolls_back_expirations_and_time_observations(): void
    {
        $h = $this->acquire();
        $before = $this->facts();
        $this->advance('+900 seconds');
        $store = $this->lab->store(beforeCommit: static function () {
            throw new LogicException('structural failure');
        });
        try {
            (new SyntheticHoldEngine($store, $this->clock))->expireDue(HoldActor::system());
            $this->fail();
        } catch (LogicException $error) {
            $this->assertSame('structural failure', $error->getMessage());
        }
        $this->assertSame($before, $this->facts($this->lab->connect()));
        $this->assertSame(HoldState::Held, $this->store->find($h->id)->state);
        $this->assertCount(1, $this->engine->expireDue(HoldActor::system()));
    }

    public function test_expired_quote_binding_cannot_be_reused_for_a_different_reservation(): void
    {
        $h = $this->acquire();
        $this->advance('+900 seconds');
        $this->violation('quote_already_bound', fn () => $this->engine->accept($this->proposal(2, quote: 'quote-1'), HoldActor::provider(110), $this->key(2)));
        $this->assertSame(HoldState::Expired, $this->store->find($h->id)->state);
        $this->assertCount(1, $this->store->all());
    }

    public function test_clock_regression_and_unknown_transactions_fail_closed(): void
    {
        $this->acquire();
        $before = $this->facts();
        $this->advance('-1 microsecond');
        $this->violation('clock_regressed', fn () => $this->engine->expireDue(HoldActor::system()));
        $this->assertSame($before, $this->facts());
        try {
            $this->store->atomic(fn () => $this->store->atomic(fn () => null));
            $this->fail();
        } catch (LogicException) {
            $this->assertSame($before, $this->facts());
        }
        $this->expectException(LogicException::class);
        $this->store->observeClock($this->clock->time);
    }
}
