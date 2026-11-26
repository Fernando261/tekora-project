<?php

declare(strict_types=1);

namespace Tests\PaymentsIntegrationPrototype;

use App\Scheduling\Holds\HoldActor;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PaymentsIntegration\IntegrationTestCase;
use Tests\Support\PaymentsIntegration\ScenarioFixture;

#[\PHPUnit\Framework\Attributes\Group('b231-C-holds')]
final class OccupancyHistoryTest extends IntegrationTestCase
{
    public static function intervals(): array
    {
        return [[0, 12, false], [1, 12, false], [3599999999, 12, false], [3600000000, 12, true], [0, 13, true]];
    }

    #[DataProvider('intervals')]
    public function test_t17_t18_t19_provider_exclusivity_with_consecutive_intervals(int $offset, int $inquiry, bool $allowed): void
    {
        $this->held();
        [, $provider] = $this->proposal(2, $inquiry, $this->start($offset));
        $r = $this->f->accept($provider, 2, 1, $this->key());
        self::assertSame($allowed ? 'held' : 'slot_conflict', $r->historical['code']);
        self::assertSame($allowed ? 2 : 1, $this->rowCount('holds'));
    }

    public static function holdBoundary(): array
    {
        return [[899999999, 'committed'], [900000000, 'expired']];
    }

    #[DataProvider('holdBoundary')]
    public function test_t21_hold_boundary_and_late_synthetic_result(int $elapsed, string $state): void
    {
        $h = $this->held()->historical['hold_id'];
        $this->clock->us += $elapsed;
        $r = $this->f->decide(HoldActor::system(), 'commit', $h, 1, $this->key(), 1001, $this->key());
        self::assertSame($state, $r->current['state']);
        self::assertSame($state === 'committed' ? 'reserved' : 'closed', $r->current['phase']);
        self::assertSame($state === 'committed' ? 'verified_fixture' : 'late_reconcile', $this->rows('financial')[0]['disposition']);
        self::assertSame(2, $this->rows('holds')[0]['version']);
        self::assertSame(2, $this->rowCount('hold_events'));
    }

    public function test_t23_replay_never_renews_and_late_replay_returns_current_expired_state(): void
    {
        $this->proposal();
        $key = $this->key();
        $a = $this->f->accept(101, 1, 1, $key);
        $before = $this->rows('activations');
        $this->clock->us += 899999999;
        $b = $this->f->accept(101, 1, 1, $key);
        self::assertSame('replayed', $b->kind);
        self::assertSame($a->historical, $b->historical);
        self::assertSame('held', $b->current['state']);
        $this->clock->us++;
        $c = $this->f->accept(101, 1, 1, $key);
        self::assertSame('expired', $c->current['state']);
        self::assertSame($before, $this->rows('activations'));
        $events = $this->rows('hold_events');
        $d = $this->f->accept(101, 1, 1, $key);
        self::assertSame('expired', $d->current['state']);
        self::assertSame($events, $this->rows('hold_events'));
        $this->rejects(fn () => $this->f->accept(101, 1, 2, $key));
        self::assertSame($a->current['expires_at_us'], $d->current['expires_at_us']);
    }

    public function test_t26_expiry_is_conserved_when_late_conversion_is_rejected(): void
    {
        $h = $this->held()->historical['hold_id'];
        $this->clock->us += 900000000;
        $r = $this->f->decide(HoldActor::system(), 'commit', $h, 1, $this->key(), 1001, $this->key());
        self::assertSame('rejected', $r->kind);
        self::assertSame('expired', $r->current['state']);
        $this->held(2, 12);
        $late = $this->f->decide(HoldActor::system(), 'commit', $h, 2, $this->key(), 1001, $this->key());
        self::assertSame('hold_terminal', $late->historical['code']);
        self::assertSame(2, $this->rowCount('claims'));
        self::assertSame('closed', $r->current['phase']);
        self::assertSame(2, $this->rowCount('financial'));
    }

    public function test_release_permissions_versions_and_terminal_no_reactivation(): void
    {
        $h = $this->held()->historical['hold_id'];
        $this->rejects(fn () => $this->f->decide(HoldActor::client(202), 'release', $h, 1, $this->key()));
        $this->rejects(fn () => $this->f->decide(HoldActor::client(201), 'release', $h, 2, $this->key()));
        $key = $this->key();
        $r = $this->f->decide(HoldActor::client(201), 'release', $h, 1, $key);
        self::assertSame('released', $r->current['state']);
        self::assertSame('replayed', $this->f->decide(HoldActor::client(201), 'release', $h, 1, $key)->kind);
        self::assertSame('hold_terminal', $this->f->decide(HoldActor::client(201), 'release', $h, 2, $this->key())->historical['code']);
        self::assertSame('proposal_terminal', $this->f->accept(101, 1, 3, $this->key())->historical['code']);
    }

    public function test_t35_cancel_closes_claim_but_preserves_committed_hold(): void
    {
        $h = $this->held()->historical['hold_id'];
        $this->f->decide(HoldActor::system(), 'commit', $h, 1, $this->key(), 1001, $this->key());
        $holds = $this->rows('holds');
        $events = $this->rows('hold_events');
        $key = $this->key();
        $r = $this->f->close(HoldActor::client(201), 1, 3, 2, 'cancelled', $key);
        self::assertSame('committed', $r->current['state']);
        self::assertSame('closed', $r->current['phase']);
        self::assertSame($holds, $this->rows('holds'));
        self::assertSame($events, $this->rows('hold_events'));
        self::assertSame('replayed', $this->f->close(HoldActor::client(201), 1, 3, 2, 'cancelled', $key)->kind);
        self::assertSame('held', $this->held(2, 12)->historical['code']);
        self::assertSame(3, $r->current['claim_version']);
    }

    public function test_basic_completion_and_cancel_after_start_scope_is_synthetic_only(): void
    {
        $h = $this->held()->historical['hold_id'];
        $this->f->decide(HoldActor::system(), 'commit', $h, 1, $this->key(), 1001, $this->key());
        $this->clock->us = $this->start();
        self::assertSame('rejected', $this->f->close(HoldActor::client(201), 1, 3, 2, 'cancelled', $this->key())->kind);
        $this->clock->us = $this->start(3600000000);
        $r = $this->f->close(HoldActor::provider(101), 1, 3, 2, 'completed', $this->key());
        self::assertSame('committed', $r->current['state']);
        self::assertSame('completed', $r->current['proposal_state']);
    }

    public function test_t38_append_only_cas_events_and_replace_guards(): void
    {
        $this->held();
        $p = $this->lab->connect();
        foreach (['DELETE FROM b231_commands', 'UPDATE b231_prices SET amount_cents=9999', "UPDATE b231_hold_events SET to_state='released'", 'INSERT OR REPLACE INTO b231_providers VALUES(99,101)', 'UPDATE b231_heads SET version=version+1 WHERE id=1', 'UPDATE b231_claims SET ends_at_us=ends_at_us+1 WHERE proposal_id=1'] as $sql) {
            $this->rejects(fn () => $p->exec($sql), \PDOException::class);
        }
        self::assertSame(1, $this->rowCount('hold_events'));
    }

    public function test_t37_new_legacy_fixture_uses_same_occupancy_authority(): void
    {
        $this->unit->close();
        $this->lab = \Tests\Support\PaymentsIntegration\OwnedIntegrationLab::create($this->start());
        $this->unit = new \Tests\Support\PaymentsIntegration\SharedSqliteUnitOfWork($this->lab, fn () => $this->clock->now());
        $this->f = new ScenarioFixture($this->unit);
        $this->proposal(1, 12);
        self::assertSame('slot_conflict', $this->f->accept(101, 1, 1, $this->key())->historical['code']);
        self::assertSame(0, $this->rowCount('holds'));
        self::assertSame(1, $this->rowCount('claims'));
        $r = $this->f->close(HoldActor::client(201), 900, 1, 1, 'cancelled', $this->key());
        self::assertSame('cancelled', $r->historical['code']);
        self::assertSame('held', $this->f->accept(101, 1, 1, $this->key())->historical['code']);
    }
}
