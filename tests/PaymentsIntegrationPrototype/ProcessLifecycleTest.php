<?php

declare(strict_types=1);

namespace Tests\PaymentsIntegrationPrototype;

use Tests\Support\PaymentsIntegration\IntegrationTestCase;
use Tests\Support\PaymentsIntegration\ProcessPeer;
use Tests\Support\PaymentsIntegration\ProcessRuntime;

#[\PHPUnit\Framework\Attributes\Group('b231-fix1-process-lifecycle')]
final class ProcessLifecycleTest extends IntegrationTestCase
{
    public function test_descriptor_attestation_rejects_an_open_sqlite_connection(): void
    {
        self::assertNotEmpty(ProcessRuntime::sqliteDescriptors());
        $this->rejects(fn () => ProcessRuntime::assertNoSqliteDescriptors());
        $this->unit->close();
        ProcessRuntime::assertNoSqliteDescriptors();
        self::assertSame([], ProcessRuntime::sqliteDescriptors());
    }

    public function test_worker_is_not_started_with_a_descriptor_without_close_on_exec(): void
    {
        [$permit] = $this->lab->capability();
        // PHP's 'e' fopen mode is deliberately absent; this is only a raw read stream.
        $stream = fopen(dirname($permit).'/fixture.sqlite', 'rb');
        try {
            $this->rejects(fn () => $this->peer($this->key()));
        } finally {
            fclose($stream);
        }
        ProcessRuntime::assertExecSafeDescriptors();
        self::assertSame(0, $this->rowCount('commands'));
    }

    private function peer(string $key, array $options = []): ProcessPeer
    {
        [$permit, $token] = $this->lab->capability();

        return new ProcessPeer($options + ['permit' => $permit, 'token' => $token, 'proposal' => 1, 'key' => $key, 'time' => $this->clock->us, 'operation' => 'accept']);
    }

    private function snapshot(): array
    {
        $p = $this->lab->connect();
        $snapshot = [];
        foreach ($p->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll(\PDO::FETCH_COLUMN) as $table) {
            $snapshot[$table] = $p->query('SELECT * FROM '.$table.' ORDER BY 1')->fetchAll();
        }

        return $snapshot;
    }

    private function closed(ProcessPeer $peer, int $exit): void
    {
        $peer->close();
        self::assertSame($exit, $peer->exitCode);
        self::assertTrue($peer->completion['clean']);
        self::assertSame(0, $peer->startup['sqlite_descriptors']);
        self::assertSame(0, $peer->completion['sqlite_descriptors']);
        if ($root = getenv('TEKORA_B231_TRACE_ROOT')) {
            $file = fopen($root.'/lifecycle-'.bin2hex(random_bytes(8)).'.json', 'xb');
            fwrite($file, json_encode(['events' => $peer->trace, 'exit_code' => $peer->exitCode], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
            fclose($file);
        }
    }

    public function test_normal_worker_commit_then_parent_commit_and_third_connection(): void
    {
        $this->proposal();
        $peer = $this->peer($this->key());
        self::assertNotSame(getmypid(), $peer->pid);
        self::assertGreaterThan(0, $peer->read()['sqlite_descriptors']);
        $peer->send('go');
        $result = $peer->read();
        self::assertSame('held', $result['historical']['code']);
        self::assertSame(1, $result['calls']);
        $this->closed($peer, 0);
        // Parent PDO was open throughout the exec/worker/normal-exit lifecycle.
        self::assertSame('committed', $this->f->publish(101, 1, 2001, 1, $this->key())->kind);
        $third = $this->lab->connect();
        self::assertSame(1, $third->query('SELECT version FROM b231_holds')->fetchColumn());
        self::assertSame(1, $third->query('SELECT count(*) FROM b231_claims')->fetchColumn());
        self::assertSame(5, $third->query('SELECT count(*) FROM b231_commands')->fetchColumn());
    }

    public function test_worker_exception_rolls_back_all_tables_and_same_intent_can_retry(): void
    {
        $this->proposal();
        $before = $this->snapshot();
        $key = $this->key();
        $peer = $this->peer($key, ['fail_stage' => 'head']);
        self::assertSame('ready', $peer->read()['phase']);
        $peer->send('go');
        self::assertSame('failed', $peer->read()['phase']);
        $this->closed($peer, 1);
        self::assertSame($before, $this->snapshot());
        $result = $this->f->accept(101, 1, 1, $key);
        self::assertSame('held', $result->historical['code']);
        self::assertSame('replayed', $this->f->accept(101, 1, 1, $key)->kind);
        self::assertSame(1, $this->rowCount('holds'));
        self::assertSame(1, $this->rowCount('claims'));
        self::assertSame(1, $this->rowCount('hold_events'));
        self::assertSame(4, $this->rowCount('commands'));
    }

    public function test_eof_at_worker_barrier_rolls_back_without_termination_signal(): void
    {
        $this->proposal();
        $before = $this->snapshot();
        [$permit] = $this->lab->capability();
        $journal = dirname($permit).'/fixture.sqlite-journal';
        $peer = $this->peer($this->key(), ['pause' => true]);
        self::assertSame('ready', $peer->read()['phase']);
        $peer->send('go');
        self::assertSame('writing', $peer->read()['phase']);
        self::assertFileExists($journal);
        self::assertSame(0, $this->rowCount('holds'));
        // close sends only EOF; the worker throws, rolls back and exits normally.
        $this->closed($peer, 1);
        self::assertFileDoesNotExist($journal);
        self::assertSame($before, $this->snapshot());
        self::assertSame('held', $this->f->accept(101, 1, 1, $this->key())->historical['code']);
    }
}
