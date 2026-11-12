<?php

declare(strict_types=1);

namespace Tests\PaymentsIntegrationPrototype;

use App\Scheduling\Holds\HoldActor;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\HoldsSqlite\HoldValues as H;
use Tests\Support\PaymentsIntegration\IntegrationTestCase;
use Tests\Support\PaymentsIntegration\ScenarioFixture;

#[\PHPUnit\Framework\Attributes\Group('b231-B-commercial')]
final class QuoteBindingTest extends IntegrationTestCase
{
    public function test_t09_t10_actors_and_financial_binding_are_server_resolved(): void
    {
        $this->rejects(fn () => $this->f->publish(102, 1, 1001, 0, $this->key()));
        $this->f->publish(101, 1, 1001, 0, $this->key());
        $this->rejects(fn () => $this->f->quote(202, 11, $this->key()));
        $q = $this->f->quote(201, 11, $this->key())->historical['quote_id'];
        $this->rejects(fn () => $this->f->propose(202, $q, 1, $this->start(), $this->key()));
        self::assertSame(0, $this->rowCount('acceptances'));
        $this->f->propose(201, $q, 1, $this->start(), $this->key());
        $this->rejects(fn () => $this->f->accept(102, 1, 1, $this->key()));
        self::assertSame(0, $this->rowCount('holds'));
        self::assertSame(0, $this->rowCount('activations'));
        $p = $this->lab->connect();
        $binding = $this->rows('bindings')[0];
        $binding['id'] = $this->key();
        $binding['proposal_id'] = 2;
        $this->proposal(2, 12);
        $p->exec('BEGIN IMMEDIATE');
        $this->rejects(fn () => $this->insert($p, 'bindings', $binding), \PDOException::class);
        $p->exec('ROLLBACK');
    }

    public function test_t11_exact_amounts_and_invalid_prices(): void
    {
        foreach ([1, 3, 10, 11, 1001, 999999999, 1000000000] as $i => $cents) {
            $this->f->publish(101, 1, $cents, $i, $this->key());
            $q = $this->f->quote(201, 11, $this->key())->historical['quote_id'];
            $c = ScenarioFixture::intent('inspect', HoldActor::system(), $this->key(), [$q]);
            $this->unit->run($c, function ($s) use ($q, $cents): array {
                $a = $s->pricing->quote($q)->price->amount;
                self::assertSame($cents, $a->commissionCents + $a->providerCents);
                self::assertSame(intdiv($cents * 15 + 50, 100), $a->commissionCents);
                self::assertSame('MXN', $a->currency);
                self::assertSame(60, $a->durationMinutes);

                return ['code' => 'inspected'];
            });
        }
        foreach ([0, -1, 1000000001] as $cents) {
            $this->rejects(fn () => $this->f->publish(101, 1, $cents, 7, $this->key()), \Throwable::class);
        }
        self::assertSame(7, $this->rowCount('prices'));
    }

    public static function badText(): array
    {
        return [['id', "00000000-0000-4000-a000-000000000000\0"], ['id', "00000000-0000-4000-a000-000000000000\0x"], ['id', "00000000-0000-4000-a000-00000000000\0"], ['id', 'A0000000-0000-4000-a000-000000000000'], ['created_at_utc', "2026-11-12 10:00:00.000000\0"], ['created_at_utc', "2026-11-12 10:00:00.000000\0x"], ['created_at_utc', "2026-11-12 10:00:00.00000\0"], ['created_at_utc', '2026-02-30 10:00:00.000000'], ['amount_cents', '1001suffix'], ['amount_cents', null]];
    }

    #[DataProvider('badText')]
    public function test_t12_sql_rejects_noncanonical_full_values(string $field, mixed $value): void
    {
        $this->f->publish(101, 1, 1001, 0, $this->key());
        $r = $this->rows('prices')[0];
        $r['id'] = $this->key();
        $r['idempotency_key'] = $this->key();
        $r['version'] = 2;
        $r['command_id'] = $this->key();
        $r[$field] = $value;
        $p = $this->lab->connect();
        $p->exec('BEGIN IMMEDIATE');
        $this->rejects(fn () => $this->insert($p, 'prices', $r), \PDOException::class);
        $p->exec('ROLLBACK');
        self::assertSame(1, $this->rowCount('prices'));
    }

    private function insert(\PDO $p, string $table, array $row): void
    {
        $q = $p->prepare('INSERT INTO b231_'.$table.'('.implode(',', array_keys($row)).') VALUES('.implode(',', array_fill(0, count($row), '?')).')');
        foreach (array_values($row) as $i => $v) {
            $q->bindValue($i + 1, $v, is_int($v) ? \PDO::PARAM_INT : ($v === null ? \PDO::PARAM_NULL : \PDO::PARAM_STR));
        }$q->execute();
        $q->closeCursor();
    }

    public function test_t13_price_change_does_not_change_accepted_snapshot(): void
    {
        [$q] = $this->proposal();
        $quotes = $this->rows('quotes');
        $acceptances = $this->rows('acceptances');
        $this->f->publish(101, 1, 9999, 1, $this->key());
        $acceptKey = $this->key();
        $r = $this->f->accept(101, 1, 1, $acceptKey);
        $this->clock->us += 1800000000;
        $resolved = $this->unit->resolve(ScenarioFixture::intent('accept', HoldActor::provider(101), $acceptKey, [1, 1]));
        self::assertSame('expired', $resolved->current['state']);
        self::assertSame($quotes, $this->rows('quotes'));
        self::assertSame($acceptances, $this->rows('acceptances'));
        self::assertSame('held', $r->current['state']);
        self::assertSame(1001, $quotes[0]['amount_cents']);
    }

    public function test_t14_quote_and_proposal_binding_cannot_be_reused(): void
    {
        [$q] = $this->proposal();
        $this->rejects(fn () => $this->f->propose(201, $q, 2, $this->start() + 3600000000, $this->key()), \PDOException::class);
        $this->rejects(fn () => $this->f->propose(201, $q, 1, $this->start(), $this->key()), \PDOException::class);
        self::assertSame(1, $this->rowCount('bindings'));
        self::assertSame(1, $this->rowCount('proposals'));
    }

    public function test_t15_expired_quote_requires_new_consent_and_proposal(): void
    {
        $this->proposal();
        $old = $this->rows('quotes');
        $this->clock->us += 1800000000;
        $r = $this->f->accept(101, 1, 1, $this->key());
        self::assertSame('rejected', $r->kind);
        self::assertSame('requote_required', $r->historical['code']);
        self::assertSame(0, $this->rowCount('holds'));
        self::assertSame('expired', $this->rows('heads')[0]['state']);
        $this->proposal(2);
        self::assertSame(2, $this->rowCount('acceptances'));
        self::assertSame($old[0], $this->rows('quotes')[array_search($old[0]['id'], array_column($this->rows('quotes'), 'id'))]);
        self::assertSame('committed', $this->f->accept(101, 2, 1, $this->key())->kind);
    }

    public static function quoteBoundary(): array
    {
        return [[1799999999, true], [1800000000, false]];
    }

    #[DataProvider('quoteBoundary')]
    public function test_t16_quote_expiry_boundary_and_freeze(int $elapsed, bool $valid): void
    {
        $this->proposal();
        $this->clock->us += $elapsed;
        $r = $this->f->accept(101, 1, 1, $this->key());
        self::assertSame($valid ? 'held' : 'requote_required', $r->historical['code']);
        if ($valid) {
            $this->clock->us += 899999999;
            $x = $this->f->decide(HoldActor::system(), 'commit', $r->historical['hold_id'], 1, $this->key(), 1001, $this->key());
            self::assertSame('committed', $x->current['state']);
            self::assertSame('reserved', $x->current['phase']);
        }
    }

    public function test_t22_offsets_microseconds_and_clock_regression(): void
    {
        $a = new \DateTimeImmutable('2026-11-12 10:00:00.123456Z');
        $b = new \DateTimeImmutable('2026-11-12 04:00:00.123456-06:00');
        self::assertSame(H::micros($a), H::micros($b));
        self::assertSame('123456', H::instant(H::micros($a))->format('u'));
        $this->proposal();
        $this->clock->us--;
        $this->rejects(fn () => $this->f->accept(101, 1, 1, $this->key()));
        self::assertSame(0, $this->rowCount('holds'));
    }

    public function test_requested_proposal_cannot_be_duplicated_for_same_inquiry(): void
    {
        $this->proposal();
        $q = $this->f->quote(201, 11, $this->key())->historical['quote_id'];
        $this->rejects(fn () => $this->f->propose(201, $q, 2, $this->start(3600000000), $this->key()), \PDOException::class);
        self::assertSame(1, $this->rowCount('proposals'));
        self::assertSame(1, $this->rowCount('acceptances'));
    }
}
