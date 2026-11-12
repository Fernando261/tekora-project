<?php

declare(strict_types=1);

namespace Tests\PaymentsIntegrationPrototype;

use App\Scheduling\Holds\HoldActor;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PaymentsIntegration\IntegrationTestCase;
use Tests\Support\PaymentsIntegration\IntegrationUncertain;
use Tests\Support\PaymentsIntegration\ProcessPeer;
use Tests\Support\PaymentsIntegration\ScenarioFixture;
use Tests\Support\PaymentsIntegration\SharedSqliteUnitOfWork;

#[\PHPUnit\Framework\Attributes\Group('b231-E-processes')]
final class RecoveryProcessesTest extends IntegrationTestCase
{
    private function peer(int $proposal, string $key, array $options = []): ProcessPeer
    {
        [$path,$token] = $this->lab->capability();

        return new ProcessPeer($options + ['permit' => $path, 'token' => $token, 'proposal' => $proposal, 'key' => $key, 'time' => $this->clock->us, 'operation' => 'accept']);
    }

    public function test_t20_independent_processes_serialize_one_winner_with_third_connection(): void
    {
        $this->proposal();
        $this->proposal(2, 12);
        $a = $this->peer(1, $this->key(), ['pause' => true]);
        $b = $this->peer(2, $this->key());
        try {
            $ar = $a->read();
            $br = $b->read();
            self::assertNotSame($ar['pid'], $br['pid']);
            self::assertNotSame(getmypid(), $ar['pid']);
            $a->send('go');
            self::assertSame('writing', $a->read()['phase']);
            self::assertSame(0, $this->rowCount('holds'));
            $b->send('go');
            $blocked = $b->read();
            self::assertSame('busy', $blocked['phase']);
            self::assertSame(0, $blocked['calls']);
            $a->send('release');
            $winner = $a->read();
            self::assertSame('held', $winner['historical']['code']);
            self::assertSame(1, $winner['calls']);
            $b->send('retry');
            $loser = $b->read();
            self::assertSame('slot_conflict', $loser['historical']['code']);
            self::assertSame(1, $loser['calls']);
            self::assertSame(1, $this->rowCount('holds'));
            self::assertSame(1, $this->rowCount('claims'));
            $this->trace('race', [$ar, $br, $winner, $loser]);
        } finally {
            $a->close();
            $b->close();
        }
    }

    public function test_t34_same_intent_recovers_from_new_pid_after_acknowledgement_loss(): void
    {
        $this->proposal();
        $key = $this->key();
        $a = $this->peer(1, $key, ['lost_ack' => true]);
        try {
            $first = $a->read();
            $a->send('go');
            $uncertain = $a->read();
            self::assertSame('uncertain', $uncertain['phase']);
        } finally {
            $a->close();
        }
        $before = $this->rows('holds');
        $this->clock->us += 900000000;
        $b = $this->peer(1, $key, ['operation' => 'resolve']);
        try {
            $second = $b->read();
            self::assertNotSame($first['pid'], $second['pid']);
            $b->send('go');
            $r = $b->read();
            self::assertSame('replayed', $r['kind']);
            self::assertSame('expired', $r['current']['state']);
            self::assertSame(0, $r['calls']);
            self::assertSame($before[0]['expires_at_us'], $r['current']['expires_at_us']);
            $this->trace('recovery', [$first, $uncertain, $second, $r]);
        } finally {
            $b->close();
        }
    }

    public function test_t34_resolution_waits_for_existing_writer_and_proves_absent_receipt(): void
    {
        $this->proposal();
        $lock = $this->lab->connect();
        $lock->exec('BEGIN IMMEDIATE');
        $b = $this->peer(1, $this->key(), ['operation' => 'resolve']);
        try {
            $b->read();
            $b->send('go');
            $r = $b->read();
            self::assertSame('busy', $r['phase']);
            $lock->exec('ROLLBACK');
            $b->send('retry');
            $r = $b->read();
            self::assertSame('not_committed', $r['kind']);
            self::assertSame(0, $r['calls']);
            self::assertSame(0, $this->rowCount('holds'));
        } finally {
            $b->close();
        }
    }

    public static function uncertainty(): array
    {
        return [['after_commit', true], ['before_commit', false], ['rollback', false]];
    }

    #[DataProvider('uncertainty')]
    public function test_t33_uncertainty_revokes_session_and_recovery_never_executes_work(string $phase, bool $present): void
    {
        $this->proposal();
        $key = $this->key();
        $unit = new SharedSqliteUnitOfWork($this->lab, fn () => $this->clock->now(), fault: static function ($p) use ($phase): void {
            if ($phase === 'rollback' && $p === 'before_seal') {
                throw new \RuntimeException('Body failure');
            }
            if ($p === $phase) {
                $e = new \PDOException('Injected '.$phase);
                $e->errorInfo = ['HY000', 10, 'synthetic'];
                throw $e;
            }
        });
        $f = new ScenarioFixture($unit);
        $this->rejects(fn () => $f->accept(101, 1, 1, $key), IntegrationUncertain::class);
        $this->rejects(fn () => $f->accept(101, 1, 1, $key));
        $intent = ScenarioFixture::intent('accept', HoldActor::provider(101), $key, [1, 1]);
        $result = $this->unit->resolve($intent);
        self::assertSame($present ? 'replayed' : 'not_committed', $result->kind);
        self::assertSame($present ? 1 : 0, $this->rowCount('holds'));
    }

    private function trace(string $case, array $events): void
    {
        $parent = getenv('TEKORA_B231_TRACE_ROOT');
        if (! $parent) {
            return;
        }
        $f = fopen($parent.'/'.$case.'-'.bin2hex(random_bytes(8)).'.json', 'xb');
        fwrite($f, json_encode($events, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        fclose($f);
    }
}
