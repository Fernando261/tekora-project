<?php

declare(strict_types=1);

namespace Tests\Support\PaymentsIntegration;

use PHPUnit\Framework\TestCase;

abstract class IntegrationTestCase extends TestCase
{
    protected OwnedIntegrationLab $lab;

    protected FixtureClock $clock;

    protected SharedSqliteUnitOfWork $unit;

    protected ScenarioFixture $f;

    protected bool $checkIntegrity = true;

    protected function setUp(): void
    {
        $this->lab = OwnedIntegrationLab::create();
        $this->clock = new FixtureClock;
        $this->unit = new SharedSqliteUnitOfWork($this->lab, fn () => $this->clock->now());
        $this->f = new ScenarioFixture($this->unit);
    }

    protected function tearDown(): void
    {
        $this->unit->close();
        if ($this->checkIntegrity) {
            $this->integrity();
        }
    }

    protected function key(): string
    {
        return CommandEnvelope::uuid();
    }

    protected function start(int $offset = 0): int
    {
        return 1794564000000000 + $offset;
    } // next day, fixture only

    protected function proposal(int $id = 1, int $inquiry = 11, ?int $start = null): array
    {
        $ctx = [11 => [101, 1, 201], 12 => [101, 2, 202], 13 => [102, 3, 201], 14 => [101, 1, 202]][$inquiry];
        [$provider,$service,$client] = $ctx;
        $pdo = $this->lab->connect();
        if ($pdo->query('SELECT count(*) FROM b231_prices WHERE service_id='.$service)->fetchColumn() === 0) {
            $this->f->publish($provider, $service, 1001, 0, $this->key());
        }
        $q = $this->f->quote($client, $inquiry, $this->key())->historical['quote_id'];
        $this->f->propose($client, $q, $id, $start ?? $this->start(), $this->key());

        return [$q, $provider, $client];
    }

    protected function held(int $id = 1, int $inquiry = 11, ?int $start = null): TransactionOutcome
    {
        [, $provider] = $this->proposal($id, $inquiry, $start);

        return $this->f->accept($provider, $id, 1, $this->key());
    }

    protected function rowCount(string $table): int
    {
        return $this->lab->connect()->query('SELECT count(*) FROM b231_'.$table)->fetchColumn();
    }

    protected function rows(string $table): array
    {
        return $this->lab->connect()->query('SELECT * FROM b231_'.$table.' ORDER BY 1,2')->fetchAll();
    }

    protected function integrity(): void
    {
        $p = $this->lab->connect();
        self::assertSame('ok', $p->query('PRAGMA integrity_check')->fetchColumn());
        self::assertSame([], $p->query('PRAGMA foreign_key_check')->fetchAll());
        self::assertSame(0, $p->query("SELECT count(*) FROM b231_claims a JOIN b231_claims b ON a.id<b.id AND a.provider_id=b.provider_id AND a.phase IN ('held','reserved') AND b.phase IN ('held','reserved') AND a.starts_at_us<b.ends_at_us AND b.starts_at_us<a.ends_at_us")->fetchColumn());
    }

    protected function rejects(callable $work, string $class = \LogicException::class): \Throwable
    {
        try {
            $work();
        } catch (\Throwable $e) {
            self::assertInstanceOf($class, $e);

            return $e;
        }
        self::fail('Expected rejection');
    }

    protected function faultAt(string $stage): ScenarioFixture
    {
        return new ScenarioFixture($this->unit, static function ($at) use ($stage): void {
            if ($at === $stage) {
                throw new \RuntimeException('Injected '.$stage);
            }
        });
    }
}
