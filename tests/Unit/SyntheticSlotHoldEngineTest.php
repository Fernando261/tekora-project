<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Scheduling\Holds\AcceptedQuoteReference;
use App\Scheduling\Holds\HoldActor;
use App\Scheduling\Holds\HoldClock;
use App\Scheduling\Holds\HoldEvent;
use App\Scheduling\Holds\HoldState;
use App\Scheduling\Holds\HoldViolation;
use App\Scheduling\Holds\ReservationProposal;
use App\Scheduling\Holds\SlotHold;
use App\Scheduling\Holds\SyntheticHoldEngine;
use App\Scheduling\Holds\UtcInterval;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemorySlotHoldStore;
use Tests\Support\MutableSlotHoldClock;

#[Group('slot-holds-b21')]
final class SyntheticSlotHoldEngineTest extends TestCase
{
    private InMemorySlotHoldStore $store;

    private MutableSlotHoldClock $clock;

    private SyntheticHoldEngine $engine;

    protected function setUp(): void
    {
        $this->store = new InMemorySlotHoldStore;
        $this->clock = new MutableSlotHoldClock(new DateTimeImmutable('2030-01-01T08:00:00.123456Z'));
        $this->engine = new SyntheticHoldEngine($this->store, $this->clock);
    }

    private function key(int $n): string
    {
        return sprintf('00000000-0000-4000-8000-%012d', $n);
    }

    private function proposal(int $reservation = 1, string $start = '10:00:00.000000', int $provider = 10, ?string $quote = null, int $version = 1): ReservationProposal
    {
        $at = new DateTimeImmutable('2030-01-01T'.$start.'Z');

        return new ReservationProposal($reservation, $version, new UtcInterval($at, $at->modify('+60 minutes')),
            new AcceptedQuoteReference($quote ?? 'quote-'.$reservation, 100 + $reservation, 1000 + $reservation,
                $provider, $provider + 100, 900 + $reservation));
    }

    private function acquire(int $reservation = 1): SlotHold
    {
        return $this->engine->accept($this->proposal($reservation), HoldActor::provider(110), $this->key($reservation));
    }

    private function assertViolation(string $reason, callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected '.$reason);
        } catch (HoldViolation $error) {
            $this->assertSame($reason, $error->reason);
        }
    }

    public function test_acceptance_of_concrete_proposal_creates_exact_900_seconds_and_history(): void
    {
        $proposal = $this->proposal();
        $this->assertSame([], $this->store->all());
        $hold = $this->engine->accept($proposal, HoldActor::provider(110), $this->key(1));
        $this->assertSame($proposal, $hold->proposal);
        $this->assertSame(HoldState::Held, $hold->state);
        $this->assertSame(1, $hold->version);
        $this->assertEquals($this->clock->time, $hold->acceptedAt);
        $this->assertSame('2030-01-01T08:15:00.123456+00:00', $hold->expiresAt->format('Y-m-d\TH:i:s.uP'));
        $events = $this->store->history($hold->id);
        $this->assertCount(1, $events);
        $this->assertSame([null, HoldState::Held, 110], [$events[0]->from, $events[0]->to, $events[0]->actor->userId]);
    }

    public static function overlaps(): array
    {
        return [['09:00:00.000001'], ['09:30:00.000000'], ['10:00:00.000000'], ['10:30:00.000000'], ['10:59:59.999999']];
    }

    #[DataProvider('overlaps')]
    public function test_incompatible_intervals_for_same_provider_are_rejected(string $start): void
    {
        $first = $this->acquire();
        $this->assertViolation('slot_conflict', fn () => $this->engine->accept($this->proposal(2, $start), HoldActor::provider(110), $this->key(2)));
        $this->assertCount(1, $this->store->all());
        $this->assertSame($first, $this->store->find($first->id));
        $this->assertCount(1, $this->store->history($first->id));
    }

    public static function consecutive(): array
    {
        return [['09:00:00.000000'], ['11:00:00.000000']];
    }

    #[DataProvider('consecutive')]
    public function test_consecutive_intervals_are_allowed(string $start): void
    {
        $this->acquire();
        $this->engine->accept($this->proposal(2, $start), HoldActor::provider(110), $this->key(2));
        $this->assertCount(2, $this->store->all());
    }

    public function test_same_interval_for_different_provider_is_allowed(): void
    {
        $this->acquire();
        $other = $this->engine->accept($this->proposal(2, provider: 20), HoldActor::provider(120), $this->key(2));
        $this->assertSame(20, $other->proposal->quote->providerProfileId);
        $this->assertCount(2, $this->store->all());
    }

    public function test_proposal_construction_and_elapsed_time_do_not_start_or_consume_retention(): void
    {
        $proposal = $this->proposal();
        $this->clock->time = $this->clock->time->modify('+1800 seconds');
        $this->assertSame([], $this->store->all());
        $hold = $this->engine->accept($proposal, HoldActor::provider(110), $this->key(1));
        $this->assertEquals($this->clock->time, $hold->acceptedAt);
        $this->assertEquals($this->clock->time->modify('+900 seconds'), $hold->expiresAt);
    }

    public function test_independent_stores_are_not_a_global_occupancy_authority(): void
    {
        $hold = $this->acquire();
        $separate = new InMemorySlotHoldStore;
        $other = (new SyntheticHoldEngine($separate, $this->clock))->accept($this->proposal(), HoldActor::provider(110), $this->key(1));
        $this->assertNotSame($hold->id, $other->id);
        $this->assertCount(1, $this->store->all());
        $this->assertCount(1, $separate->all());
    }

    public static function deadlines(): array
    {
        return [['+899 seconds', false], ['+899 seconds +999999 microseconds', false],
            ['+900 seconds', true], ['+900 seconds +1 microsecond', true]];
    }

    #[DataProvider('deadlines')]
    public function test_server_clock_determines_exact_expiration_boundary(string $offset, bool $expired): void
    {
        $hold = $this->acquire();
        $this->clock->time = $hold->acceptedAt->modify($offset);
        $changes = $this->engine->expireDue(HoldActor::system());
        $current = $this->store->find($hold->id);
        $this->assertCount($expired ? 1 : 0, $changes);
        $this->assertSame($expired ? HoldState::Expired : HoldState::Held, $current->state);
        $this->assertSame($expired ? 2 : 1, $current->version);
        $this->assertEquals($hold->expiresAt, $current->expiresAt);
    }

    public function test_acquire_retries_from_another_engine_keep_id_clock_and_history(): void
    {
        $first = $this->acquire();
        $this->clock->time = $this->clock->time->modify('+899 seconds');
        $other = new SyntheticHoldEngine($this->store, $this->clock);
        $replay = $other->accept($this->proposal(), HoldActor::provider(110), strtoupper($this->key(1)));
        $this->assertSame($first, $replay);
        $this->assertCount(1, $this->store->history($first->id));
        $this->assertEquals($first->expiresAt, $replay->expiresAt);
    }

    public function test_retry_at_deadline_returns_terminal_record_and_never_renews(): void
    {
        $hold = $this->acquire();
        $this->clock->time = $hold->expiresAt;
        $replay = $this->engine->accept($this->proposal(), HoldActor::provider(110), $this->key(1));
        $this->assertSame($hold->id, $replay->id);
        $this->assertSame(HoldState::Expired, $replay->state);
        $this->assertEquals($hold->expiresAt, $replay->expiresAt);
        $this->assertCount(2, $this->store->history($hold->id));
        $this->assertSame($replay, $this->engine->accept($this->proposal(), HoldActor::provider(110), $this->key(1)));
    }

    public function test_different_key_cannot_reactivate_same_reservation(): void
    {
        $hold = $this->acquire();
        $this->clock->time = $hold->expiresAt;
        $this->assertViolation('reservation_already_bound', fn () => $this->engine->accept($this->proposal(), HoldActor::provider(110), $this->key(2)));
        $this->assertSame(HoldState::Expired, $this->store->find($hold->id)->state);
        $this->assertCount(2, $this->store->history($hold->id));
    }

    public function test_on_demand_expiration_allows_new_reservation_but_keeps_previous_history(): void
    {
        $old = $this->acquire();
        $this->clock->time = $old->expiresAt;
        $new = $this->acquire(2);
        $this->assertNotSame($old->id, $new->id);
        $this->assertCount(2, $this->store->all());
        $this->assertSame(HoldState::Expired, $this->store->find($old->id)->state);
        $this->assertCount(2, $this->store->history($old->id));
        $this->assertSame('system', $this->store->history($old->id)[1]->actor->kind);
    }

    public function test_quote_cannot_be_bound_to_a_second_reservation_after_expiry(): void
    {
        $old = $this->acquire();
        $this->clock->time = $old->expiresAt;
        $this->assertViolation('quote_already_bound', fn () => $this->engine->accept($this->proposal(2, quote: 'quote-1'), HoldActor::provider(110), $this->key(2)));
        $this->assertCount(1, $this->store->all());
    }

    public static function changedIntentions(): array
    {
        return [['reservation'], ['interval'], ['quote'], ['proposal_version'], ['service'], ['client']];
    }

    #[DataProvider('changedIntentions')]
    public function test_uuid_cannot_be_reused_for_changed_proposal(string $changed): void
    {
        $original = $this->acquire();
        $proposal = match ($changed) {
            'reservation' => $this->proposal(2),
            'interval' => $this->proposal(start: '11:00:00.000000'),
            'quote' => $this->proposal(quote: 'another-quote'),
            'proposal_version' => $this->proposal(version: 2),
            'service', 'client' => new ReservationProposal(1, 1, $original->proposal->interval,
                new AcceptedQuoteReference('quote-1', 101, $changed === 'service' ? 9001 : 1001, 10, 110, $changed === 'client' ? 9001 : 901)),
        };
        $this->assertViolation('idempotency_conflict', fn () => $this->engine->accept($proposal, HoldActor::provider(110), $this->key(1)));
        $this->assertSame($original, $this->store->find($original->id));
    }

    public static function releasingParties(): array
    {
        return [['provider', 110], ['client', 901]];
    }

    #[DataProvider('releasingParties')]
    public function test_participant_can_release_and_replay_without_duplicate_event(string $kind, int $id): void
    {
        $hold = $this->acquire();
        $actor = $kind === 'provider' ? HoldActor::provider($id) : HoldActor::client($id);
        $released = $this->engine->release($hold->id, $actor, 1, $this->key(2));
        $this->assertSame(HoldState::Released, $released->state);
        $this->assertSame(2, $released->version);
        $this->assertSame($released, $this->engine->release($hold->id, $actor, 1, $this->key(2)));
        $this->assertCount(2, $this->store->history($hold->id));
        $this->assertSame($id, $this->store->history($hold->id)[1]->actor->userId);
        $this->engine->accept($this->proposal(2), HoldActor::provider(110), $this->key(90));
        $this->assertCount(2, $this->store->all());
    }

    public function test_wrong_provider_or_client_cannot_accept_or_release_and_system_cannot_fake_release(): void
    {
        $hold = $this->acquire();
        foreach ([HoldActor::provider(111), HoldActor::client(902), HoldActor::system()] as $actor) {
            $this->assertViolation('unauthorized', fn () => $this->engine->accept($this->proposal(2), $actor, $this->key(2)));
            $this->assertViolation('unauthorized', fn () => $this->engine->release($hold->id, $actor, 1, $this->key(3)));
        }
        $this->assertSame($hold, $this->store->find($hold->id));
        $this->assertCount(1, $this->store->history($hold->id));
    }

    public function test_only_system_assertion_can_expire_or_commit(): void
    {
        $hold = $this->acquire();
        foreach ([HoldActor::provider(110), HoldActor::client(901)] as $actor) {
            $this->assertViolation('unauthorized', fn () => $this->engine->expireDue($actor));
            $this->assertViolation('unauthorized', fn () => $this->engine->commit($hold->id, $actor, 1, $this->key(2)));
        }
        $this->assertCount(1, $this->store->history($hold->id));
    }

    public function test_wrong_versions_are_rejected_without_state_or_history_change(): void
    {
        $hold = $this->acquire();
        $this->assertViolation('version_conflict', fn () => $this->engine->release($hold->id, HoldActor::client(901), 2, $this->key(2)));
        $this->assertViolation('version_conflict', fn () => $this->engine->commit($hold->id, HoldActor::system(), 2, $this->key(3)));
        $this->assertSame($hold, $this->store->find($hold->id));
        $this->assertCount(1, $this->store->history($hold->id));
    }

    public function test_commitment_keeps_same_occupancy_and_does_not_expire_at_900_seconds(): void
    {
        $hold = $this->acquire();
        $this->clock->time = $hold->acceptedAt->modify('+899 seconds');
        $committed = $this->engine->commit($hold->id, HoldActor::system(), 1, $this->key(2));
        $this->assertSame($hold->id, $committed->id);
        $this->assertSame($hold->proposal, $committed->proposal);
        $this->assertSame(HoldState::Committed, $committed->state);
        $this->assertSame(2, $committed->version);
        $this->assertCount(1, $this->store->all());
        $this->assertViolation('slot_conflict', fn () => $this->acquire(2));
        $this->clock->time = $hold->expiresAt->modify('+1 hour');
        $this->assertSame([], $this->engine->expireDue(HoldActor::system()));
        $this->assertSame($committed, $this->engine->commit($hold->id, HoldActor::system(), 1, $this->key(2)));
        $this->assertViolation('slot_conflict', fn () => $this->acquire(2));
        $this->assertCount(2, $this->store->history($hold->id));
    }

    public function test_late_conversion_persists_expiration_and_cannot_displace_new_hold(): void
    {
        $old = $this->acquire();
        $this->clock->time = $old->expiresAt;
        $this->assertViolation('invalid_state', fn () => $this->engine->commit($old->id, HoldActor::system(), 1, $this->key(2)));
        $this->assertSame(HoldState::Expired, $this->store->find($old->id)->state);
        $new = $this->acquire(2);
        $this->assertViolation('invalid_state', fn () => $this->engine->commit($old->id, HoldActor::system(), 2, $this->key(3)));
        $this->assertSame($new, $this->store->find($new->id));
        $this->assertCount(2, $this->store->history($old->id));
    }

    public function test_late_release_does_not_erase_or_relabel_expiration(): void
    {
        $old = $this->acquire();
        $this->clock->time = $old->expiresAt;
        $this->assertViolation('invalid_state', fn () => $this->engine->release($old->id, HoldActor::client(901), 1, $this->key(2)));
        $this->assertSame(HoldState::Expired, $this->store->find($old->id)->state);
        $this->assertSame(HoldState::Expired, $this->store->history($old->id)[1]->to);
    }

    public function test_released_and_committed_states_cannot_be_reactivated_or_released_again(): void
    {
        $released = $this->acquire();
        $this->engine->release($released->id, HoldActor::provider(110), 1, $this->key(2));
        $this->assertViolation('invalid_state', fn () => $this->engine->commit($released->id, HoldActor::system(), 2, $this->key(3)));
        $other = $this->engine->accept($this->proposal(2), HoldActor::provider(110), $this->key(90));
        $this->engine->commit($other->id, HoldActor::system(), 1, $this->key(4));
        $this->assertViolation('invalid_state', fn () => $this->engine->release($other->id, HoldActor::provider(110), 2, $this->key(5)));
    }

    public function test_successful_key_cannot_be_reused_for_different_command_or_version(): void
    {
        $held = $this->acquire();
        $committed = $this->engine->commit($held->id, HoldActor::system(), 1, $this->key(2));
        $this->assertViolation('idempotency_conflict', fn () => $this->engine->commit($held->id, HoldActor::system(), 2, $this->key(2)));
        $this->assertSame($committed, $this->store->find($held->id));
        $other = $this->engine->accept($this->proposal(2, '11:00:00.000000'), HoldActor::provider(110), $this->key(3));
        $this->assertViolation('idempotency_conflict', fn () => $this->engine->release($other->id, HoldActor::provider(110), 1, $this->key(3)));
    }

    public function test_clock_regression_fails_closed_without_changing_deadline(): void
    {
        $hold = $this->acquire();
        $this->clock->time = $hold->acceptedAt->modify('-1 microsecond');
        $this->assertViolation('clock_regressed', fn () => $this->engine->commit($hold->id, HoldActor::system(), 1, $this->key(2)));
        $this->assertSame($hold, $this->store->find($hold->id));
        $this->assertCount(1, $this->store->history($hold->id));
        $this->clock->time = $hold->acceptedAt;
        $this->assertSame($hold, $this->engine->accept($this->proposal(), HoldActor::provider(110), $this->key(1)));
    }

    public function test_creation_samples_clock_at_recording_without_counting_prior_work(): void
    {
        $start = $this->clock->time;
        $clock = new class($start) implements HoldClock
        {
            private int $reads = 0;

            public function __construct(private DateTimeImmutable $start) {}

            public function now(): DateTimeImmutable
            {
                return $this->reads++ === 0 ? $this->start : $this->start->modify('+10 seconds');
            }
        };
        $hold = (new SyntheticHoldEngine($this->store, $clock))->accept($this->proposal(), HoldActor::provider(110), $this->key(1));
        $this->assertEquals($start->modify('+10 seconds'), $hold->acceptedAt);
        $this->assertEquals($start->modify('+910 seconds'), $hold->expiresAt);
    }

    public function test_conversion_rechecks_clock_before_deciding_not_just_at_command_entry(): void
    {
        $hold = $this->acquire();
        $clock = new class($hold) implements HoldClock
        {
            private int $reads = 0;

            public function __construct(private SlotHold $hold) {}

            public function now(): DateTimeImmutable
            {
                return $this->reads++ === 0 ? $this->hold->acceptedAt->modify('+899 seconds') : $this->hold->expiresAt;
            }
        };
        $engine = new SyntheticHoldEngine($this->store, $clock);
        $this->assertViolation('invalid_state', fn () => $engine->commit($hold->id, HoldActor::system(), 1, $this->key(2)));
        $this->assertSame(HoldState::Expired, $this->store->find($hold->id)->state);
        $this->assertCount(2, $this->store->history($hold->id));
    }

    public function test_interruption_before_atomic_commit_rolls_back_hold_history_clock_and_receipt(): void
    {
        $this->store->beforeCommit = function () {
            $this->store->beforeCommit = null;
            throw new \RuntimeException('synthetic interruption');
        };
        try {
            $this->acquire();
            $this->fail('The synthetic interruption must be visible.');
        } catch (\RuntimeException $error) {
            $this->assertSame('synthetic interruption', $error->getMessage());
        }
        $this->assertSame([], $this->store->all());
        $this->assertNull($this->store->lastClock());
        $this->assertNull($this->store->receipt('provider:110:'.$this->key(1)));
        $this->clock->time = $this->clock->time->modify('+100 seconds');
        $hold = $this->acquire();
        $this->assertEquals($this->clock->time, $hold->acceptedAt);
        $this->assertCount(1, $this->store->history($hold->id));
    }

    public function test_failed_conversion_rolls_back_everything_and_retry_keeps_same_slot(): void
    {
        $hold = $this->acquire();
        $this->store->beforeCommit = function () {
            $this->store->beforeCommit = null;
            throw new \RuntimeException('commit failed');
        };
        try {
            $this->engine->commit($hold->id, HoldActor::system(), 1, $this->key(2));
            $this->fail('Fault injection must interrupt conversion.');
        } catch (\RuntimeException $error) {
            $this->assertSame('commit failed', $error->getMessage());
        }
        $this->assertSame($hold, $this->store->find($hold->id));
        $this->assertCount(1, $this->store->history($hold->id));
        $this->assertSame(HoldState::Committed, $this->engine->commit($hold->id, HoldActor::system(), 1, $this->key(2))->state);
        $this->assertCount(2, $this->store->history($hold->id));
    }

    public function test_store_rejects_writes_outside_transaction_and_reentry(): void
    {
        try {
            $this->store->observeClock($this->clock->time);
            $this->fail('Unbounded write must be rejected.');
        } catch (\LogicException) {
            $this->assertNull($this->store->lastClock());
        }
        try {
            $this->store->atomic(fn () => $this->acquire());
            $this->fail('A nested synthetic transaction must not claim serialization support.');
        } catch (\LogicException) {
            $this->assertSame([], $this->store->all());
        }
    }

    public function test_store_rejects_forged_transition_history_and_direct_overlap(): void
    {
        $held = $this->acquire();
        $next = $held->transition(HoldState::Committed, $this->clock->time);
        $this->assertViolation('invalid_history', fn () => $this->store->atomic(fn () => $this->store->transition($next, 1,
            new HoldEvent($held->id, 2, HoldState::Held, HoldState::Committed, HoldActor::client(901), $this->clock->time))));
        $other = SlotHold::start($this->proposal(2), $this->clock->time);
        $this->assertViolation('slot_conflict', fn () => $this->store->atomic(fn () => $this->store->insert($other,
            new HoldEvent($other->id, 1, null, HoldState::Held, HoldActor::provider(110), $this->clock->time))));
        $this->assertSame($held, $this->store->find($held->id));
        $this->assertCount(1, $this->store->all());
    }

    public function test_store_cannot_record_a_time_different_from_the_observed_server_clock(): void
    {
        $future = $this->clock->time->modify('+1 day');
        $held = SlotHold::start($this->proposal(), $future);
        $this->assertViolation('invalid_history', fn () => $this->store->atomic(function () use ($held, $future) {
            $this->store->observeClock($this->clock->time);
            $this->store->insert($held, new HoldEvent($held->id, 1, null, HoldState::Held, HoldActor::provider(110), $future));
        }));
        $this->assertSame([], $this->store->all());
        $this->assertNull($this->store->lastClock());
    }

    public static function changedStoredBindings(): array
    {
        return [['interval'], ['quote'], ['proposal_version'], ['acceptance_clock']];
    }

    #[DataProvider('changedStoredBindings')]
    public function test_reconstitution_does_not_allow_storage_to_replace_a_binding(string $changed): void
    {
        $old = $this->acquire();
        $proposal = match ($changed) {
            'interval' => $this->proposal(start: '11:00:00.000000'),
            'quote' => $this->proposal(quote: 'replacement'),
            'proposal_version' => $this->proposal(version: 2),
            default => $old->proposal,
        };
        $accepted = $changed === 'acceptance_clock' ? $old->acceptedAt->modify('+1 second') : $old->acceptedAt;
        $next = SlotHold::fromStorage($old->id, $proposal, HoldState::Committed, 2,
            $accepted, $accepted->modify('+900 seconds'), $accepted);
        $this->assertViolation('immutable_binding', fn () => $this->store->atomic(fn () => $this->store->transition($next, 1,
            new HoldEvent($next->id, 2, HoldState::Held, HoldState::Committed, HoldActor::system(), $next->updatedAt))));
        $this->assertSame($old, $this->store->find($old->id));
    }

    public function test_history_and_nested_proposal_are_immutable(): void
    {
        $hold = $this->acquire();
        $history = $this->store->history($hold->id);
        unset($history[0]);
        $this->assertCount(1, $this->store->history($hold->id));
        $this->expectException(\Error::class);
        $hold->proposal->quote->providerProfileId = 999;
    }

    public static function conflictingProviderIdentities(): array
    {
        return [[10, 111], [20, 110]];
    }

    #[DataProvider('conflictingProviderIdentities')]
    public function test_known_provider_identity_cannot_be_changed_by_another_reference(int $profile, int $user): void
    {
        $hold = $this->acquire();
        $at = new DateTimeImmutable('2030-01-01T11:00:00Z');
        $proposal = new ReservationProposal(2, 1, new UtcInterval($at, $at->modify('+1 hour')),
            new AcceptedQuoteReference('quote-2', 102, 1002, $profile, $user, 902));
        $this->assertViolation('provider_identity_conflict', fn () => $this->engine->accept($proposal, HoldActor::provider($user), $this->key(90)));
        $this->assertSame($hold, $this->store->find($hold->id));
        $this->assertCount(1, $this->store->all());
    }

    public static function invalidKeys(): array
    {
        return [[''], ['not-a-uuid'], ["00000000-0000-4000-8000-000000000001\n"]];
    }

    #[DataProvider('invalidKeys')]
    public function test_invalid_intentions_do_not_acquire_any_slot(string $key): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->engine->accept($this->proposal(), HoldActor::provider(110), $key);
    }
}
