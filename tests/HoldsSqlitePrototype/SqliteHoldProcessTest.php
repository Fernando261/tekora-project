<?php

declare(strict_types=1);

namespace Tests\HoldsSqlitePrototype;

use App\Scheduling\Holds\HoldState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\HoldsSqlite\HoldFixture;
use Tests\Support\HoldsSqlite\ProcessPeer;

#[Group('b222-sqlite-processes')]
final class SqliteHoldProcessTest extends TestCase
{
    use HoldFixture;

    private function peer(array $config = []): ProcessPeer
    {
        return new ProcessPeer(['ticket' => $this->lab->ticket(), 'key' => $this->key(1), ...$config]);
    }

    private function thirdConnectionIntegrity(): void
    {
        $pdo = $this->lab->connect();
        $this->assertSame('ok', $pdo->query('PRAGMA integrity_check')->fetchColumn());
        $this->assertSame([], $pdo->query('PRAGMA foreign_key_check')->fetchAll());
    }

    private function record(array $peers): void
    {
        $json = json_encode(['scenario' => $this->nameWithDataSet(), 'coordinator_pid' => getmypid(), 'traces' => array_map(fn (ProcessPeer $p) => ['protocol' => $p->trace, 'stderr' => $p->stderr], $peers)], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        $file = fopen($this->lab->ticket()['directory'].'/process-'.bin2hex(random_bytes(8)).'.json', 'x+b');
        fwrite($file, $json);
        fclose($file);
        foreach ($peers as $peer) {
            $peer->stop();
        }
    }

    public static function races(): array
    {
        return [
            'same-slot' => [['reservation' => 2, 'key' => '00000000-0000-4000-8000-000000000002'], 'slot_conflict', 1],
            'partial-overlap' => [['reservation' => 2, 'start' => '10:59:59.999999', 'key' => '00000000-0000-4000-8000-000000000002'], 'slot_conflict', 1],
            'adjacent' => [['reservation' => 2, 'start' => '11:00:00.000001', 'key' => '00000000-0000-4000-8000-000000000002'], 'held', 2],
            'other-provider' => [['reservation' => 2, 'provider' => 20, 'provider_user' => 120, 'key' => '00000000-0000-4000-8000-000000000002'], 'held', 2],
            'same-intention' => [[], 'held', 1],
        ];
    }

    #[DataProvider('races')]
    public function test_two_independent_processes_share_one_persistent_authority(array $second, string $expected, int $count): void
    {
        $a = $this->peer(['pause' => true]);
        $b = $this->peer($second);
        try {
            $ar = $a->receive('READY');
            $br = $b->receive('READY');
            $this->assertNotSame($ar['pid'], $br['pid']);
            $this->assertNotSame(getmypid(), $ar['pid']);
            $a->command('RUN');
            $a->receive('LOCK_ACQUIRED');
            $b->command('RUN');
            $busy = $b->receive('BUSY');
            $this->assertSame('begin', $busy['sqlite_phase']);
            $a->command('CONTINUE');
            $winner = $a->receive('RESULT');
            $this->assertSame('held', $winner['outcome']);
            $this->assertSame(0, $a->finish());
            $b->command('RETRY');
            $loser = $b->receive('RESULT');
            $this->assertSame($expected, $loser['outcome']);
            $this->assertSame(0, $b->finish());
            $third = $this->lab->store();
            $holds = $third->all();
            $this->assertCount($count, $holds);
            foreach ($holds as $hold) {
                $this->assertSame(HoldState::Held, $hold->state);
                $this->assertCount(1, $third->history($hold->id));
                $this->assertEquals($this->clock->time, $hold->acceptedAt);
            }
            if ($second === []) {
                $this->assertSame($winner['hold_id'], $loser['hold_id']);
                $this->assertSame($winner['expires_at'], $loser['expires_at']);
            }
            $facts = $this->facts($this->lab->connect());
            $this->assertCount($count, $facts['b222_hold_receipts']);
            $this->thirdConnectionIntegrity();
        } finally {
            $this->record([$a, $b]);
        }
    }

    public static function commitBoundary(): array
    {
        return [['2030-01-01T08:15:00.123455Z', 'committed', 'slot_conflict', HoldState::Committed], ['2030-01-01T08:15:00.123456Z', 'invalid_state', 'held', HoldState::Expired]];
    }

    #[DataProvider('commitBoundary')]
    public function test_commit_expiry_and_new_acquisition_are_serialized_at_exact_deadline(string $time, string $firstOutcome, string $secondOutcome, HoldState $oldState): void
    {
        $h = $this->acquire();
        $a = $this->peer(['pause' => true, 'command' => 'commit', 'hold_id' => $h->id, 'key' => $this->key(2), 'time' => $time]);
        $b = $this->peer(['reservation' => 2, 'key' => $this->key(3), 'time' => '2030-01-01T08:15:00.123456Z']);
        try {
            $a->receive('READY');
            $b->receive('READY');
            $a->command('RUN');
            $a->receive('LOCK_ACQUIRED');
            $b->command('RUN');
            $b->receive('BUSY');
            $a->command('CONTINUE');
            $this->assertSame($firstOutcome, $a->receive('RESULT')['outcome']);
            $this->assertSame(0, $a->finish());
            $b->command('RETRY');
            $this->assertSame($secondOutcome, $b->receive('RESULT')['outcome']);
            $this->assertSame(0, $b->finish());
            $reader = $this->lab->store();
            $this->assertSame($oldState, $reader->find($h->id)->state);
            $this->assertCount(2, $reader->history($h->id));
            $this->assertCount($oldState === HoldState::Expired ? 2 : 1, $reader->all());
            $this->thirdConnectionIntegrity();
        } finally {
            $this->record([$a, $b]);
        }
    }

    public static function exits(): array
    {
        return [['exit_before', 'BEFORE_COMMIT', false], ['exit_after', 'COMMITTED', true]];
    }

    #[DataProvider('exits')]
    public function test_process_restart_recovers_committed_intention_or_rolls_back_uncommitted_state(string $mode, string $phase, bool $committed): void
    {
        $a = $this->peer(['mode' => $mode]);
        $b = null;
        try {
            $a->receive('READY');
            $a->command('RUN');
            $a->receive($phase);
            $this->assertSame(0, $a->finish());
            $third = $this->lab->store();
            $before = $third->forReservation(1);
            $this->assertSame($committed, $before !== null);
            if (! $committed) {
                $this->assertSame([], $third->all());
                $this->assertNull($third->lastClock());
                $this->assertSame([], $this->facts()['b222_hold_receipts']);
            }
            $b = $this->peer();
            $b->receive('READY');
            $b->command('RUN');
            $result = $b->receive('RESULT');
            $this->assertSame('held', $result['outcome']);
            $this->assertSame(0, $b->finish());
            $after = $this->lab->store()->forReservation(1);
            $this->assertCount(1, $this->store->all());
            $this->assertCount(1, $this->store->history($after->id));
            $this->assertCount(1, $this->facts()['b222_hold_receipts']);
            if ($committed) {
                $this->assertEquals($before, $after);
            }
            $this->thirdConnectionIntegrity();
        } finally {
            $this->record(array_values(array_filter([$a, $b])));
        }
    }

    public function test_release_replay_across_processes_preserves_one_terminal_event(): void
    {
        $h = $this->acquire();
        $config = ['command' => 'release', 'hold_id' => $h->id, 'key' => $this->key(2)];
        $a = $this->peer($config);
        $b = $this->peer($config);
        try {
            $a->receive('READY');
            $b->receive('READY');
            $a->command('RUN');
            $first = $a->receive('RESULT');
            $this->assertSame(0, $a->finish());
            $b->command('RUN');
            $second = $b->receive('RESULT');
            $this->assertSame(0, $b->finish());
            $this->assertSame('released', $first['outcome']);
            $this->assertSame($first['hold_id'], $second['hold_id']);
            $this->assertSame($first['expires_at'], $second['expires_at']);
            $this->assertCount(2, $this->lab->store()->history($h->id));
            $this->assertCount(2, $this->facts()['b222_hold_receipts']);
            $new = $this->engine->accept($this->proposal(2), \App\Scheduling\Holds\HoldActor::provider(110), $this->key(3));
            $this->assertSame(HoldState::Held, $new->state);
            $this->thirdConnectionIntegrity();
        } finally {
            $this->record([$a, $b]);
        }
    }
}
