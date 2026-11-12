<?php

declare(strict_types=1);

namespace Tests\Support\HoldsSqlite;

use App\Scheduling\Holds\AcceptedQuoteReference;
use App\Scheduling\Holds\HoldActor;
use App\Scheduling\Holds\HoldViolation;
use App\Scheduling\Holds\ReservationProposal;
use App\Scheduling\Holds\SlotHold;
use App\Scheduling\Holds\SyntheticHoldEngine;
use App\Scheduling\Holds\UtcInterval;
use DateTimeImmutable;
use PDO;
use Tests\Support\MutableSlotHoldClock;

trait HoldFixture
{
    private SyntheticHoldDatabase $lab;

    private PDO $pdo;

    private SqliteHoldStore $store;

    private MutableSlotHoldClock $clock;

    private SyntheticHoldEngine $engine;

    protected function setUp(): void
    {
        $this->lab = SyntheticHoldDatabase::create();
        $this->pdo = $this->lab->connect();
        $this->store = $this->lab->store($this->pdo);
        $this->clock = new MutableSlotHoldClock(new DateTimeImmutable('2030-01-01T08:00:00.123456Z'));
        $this->engine = new SyntheticHoldEngine($this->store, $this->clock);
    }

    protected function tearDown(): void
    {
        $this->assertSame('ok', $this->pdo->query('PRAGMA integrity_check')->fetchColumn());
        $this->assertSame([], $this->pdo->query('PRAGMA foreign_key_check')->fetchAll());
    }

    private function key(int $n): string
    {
        return sprintf('00000000-0000-4000-8000-%012d', $n);
    }

    private function proposal(int $reservation = 1, string $start = '10:00:00.000001', int $provider = 10, ?string $quote = null, int $version = 1): ReservationProposal
    {
        $at = new DateTimeImmutable('2030-01-01T'.$start.'Z');

        return new ReservationProposal($reservation, $version, new UtcInterval($at, $at->modify('+1 hour')), new AcceptedQuoteReference($quote ?? 'quote-'.$reservation, 100 + $reservation, 1000 + $reservation, $provider, $provider + 100, 900 + $reservation));
    }

    private function acquire(int $id = 1): SlotHold
    {
        return $this->engine->accept($this->proposal($id), HoldActor::provider(110), $this->key($id));
    }

    private function advance(string $by): void
    {
        $this->clock->time = $this->clock->time->modify($by);
    }

    private function violation(string $reason, callable $operation): void
    {
        try {
            $operation();
        } catch (HoldViolation $error) {
            $this->assertSame($reason, $error->reason);

            return;
        }
        $this->fail('Expected '.$reason);
    }

    private function facts(?PDO $pdo = null): array
    {
        $pdo ??= $this->pdo;
        $facts = [];
        foreach (['b222_provider_identities', 'b222_holds', 'b222_hold_events', 'b222_hold_receipts', 'b222_clock'] as $table) {
            $facts[$table] = $pdo->query('SELECT * FROM main.'.$table.' ORDER BY 1,2')->fetchAll();
        }

        return $facts;
    }

    private function rawInsert(string $table, array $row, string $verb = 'INSERT'): void
    {
        $s = $this->pdo->prepare($verb.' INTO main.'.$table.' ('.implode(',', array_keys($row)).') VALUES ('.implode(',', array_fill(0, count($row), '?')).')');
        foreach (array_values($row) as $i => $v) {
            $s->bindValue($i + 1, $v, match (true) {
                is_int($v) => PDO::PARAM_INT,$v === null => PDO::PARAM_NULL,default => PDO::PARAM_STR
            });
        }
        try {
            $s->execute();
        } finally {
            $s->closeCursor();
        }
    }
}
