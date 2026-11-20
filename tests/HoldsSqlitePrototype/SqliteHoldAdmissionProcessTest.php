<?php

declare(strict_types=1);

namespace Tests\HoldsSqlitePrototype;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\HoldsSqlite\HoldFixture;

#[Group('b222-fix1')]
#[Group('b222-fix1-processes')]
final class SqliteHoldAdmissionProcessTest extends TestCase
{
    use HoldFixture;

    private array $workers = [];

    private function worker(string $mode): int
    {
        $pipes = [];
        $process = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', __DIR__.'/../Support/HoldsSqlite/AdmissionWorker.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, options: ['bypass_shell' => true]);
        if (! is_resource($process)) {
            throw new RuntimeException('Fix1 worker unavailable');
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $id = count($this->workers);
        $this->workers[$id] = ['process' => $process, 'pipes' => $pipes, 'buffer' => '', 'events' => [], 'stderr' => ''];
        fwrite($pipes[0], json_encode(['mode' => $mode, 'cap' => $this->lab->ticket()], JSON_THROW_ON_ERROR)."\n");
        fflush($pipes[0]);

        return $id;
    }

    private function step(int $id, string $step): void
    {
        $p = $this->workers[$id]['pipes'][0];
        fwrite($p, json_encode(['step' => $step], JSON_THROW_ON_ERROR)."\n");
        fflush($p);
        $this->workers[$id]['events'][] = ['sent' => $step];
    }

    private function message(int $id, string $expected): array
    {
        $w = &$this->workers[$id];
        $deadline = hrtime(true) + 10000000000;
        while (! str_contains($w['buffer'], "\n")) {
            if (hrtime(true) >= $deadline) {
                throw new RuntimeException('Fix1 barrier timeout '.$expected);
            }
            $read = [$w['pipes'][1]];
            $write = null;
            $except = null;
            if (stream_select($read, $write, $except, 0, 100000) > 0) {
                $chunk = stream_get_contents($w['pipes'][1]);
                if ($chunk === '' && feof($w['pipes'][1])) {
                    throw new RuntimeException('Worker exited '.stream_get_contents($w['pipes'][2]));
                }$w['buffer'] .= $chunk;
            }
        }
        [$line,$w['buffer']] = explode("\n", $w['buffer'], 2);
        $m = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        $w['events'][] = $m;
        $this->assertSame($expected, $m['event']);

        return $m;
    }

    private function finishWorker(int $id): void
    {
        $w = &$this->workers[$id];
        $deadline = hrtime(true) + 5000000000;
        do {
            $status = proc_get_status($w['process']);
            if (! $status['running']) {
                $w['stderr'] .= stream_get_contents($w['pipes'][2]);
                foreach ($w['pipes'] as $p) {
                    fclose($p);
                }$code = proc_close($w['process']);
                $w['process'] = null;
                $this->assertSame(0, $code === -1 ? $status['exitcode'] : $code);

                return;
            }usleep(1000);
        } while (hrtime(true) < $deadline);
        throw new RuntimeException('Fix1 worker exit timeout');
    }

    private function retainAndStop(): void
    {
        foreach ($this->workers as &$w) {
            if (is_resource($w['process'])) {
                $status = proc_get_status($w['process']);
                if ($status['running']) {
                    proc_terminate($w['process']);
                }$w['stderr'] .= stream_get_contents($w['pipes'][2]);
                foreach ($w['pipes'] as $p) {
                    if (is_resource($p)) {
                        fclose($p);
                    }
                }proc_close($w['process']);
                $w['process'] = null;
            }
        }
        unset($w);
        $file = fopen($this->lab->ticket()['directory'].'/fix1-process-'.bin2hex(random_bytes(8)).'.json', 'xb');
        fwrite($file, json_encode(['scenario' => $this->nameWithDataSet(), 'coordinator_pid' => getmypid(), 'traces' => array_map(fn ($w) => ['protocol' => $w['events'], 'stderr' => $w['stderr']], $this->workers)], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        fclose($file);
    }

    public static function cases(): array
    {
        return [['admission', false], ['admission', true], ['connect', false], ['connect', true]];
    }

    #[DataProvider('cases')]
    public function test_preparation_contention_is_controlled_across_independent_processes(string $mode, bool $exhaust): void
    {
        $block = $this->worker('block');
        $client = $this->worker($mode);
        try {
            $a = $this->message($block, 'READY');
            $b = $this->message($client, 'READY');
            $this->assertNotSame($a['pid'], $b['pid']);
            $this->assertNotSame(getmypid(), $b['pid']);
            $this->step($block, 'RUN');
            $this->message($block, 'LOCKED');
            $this->step($client, 'RUN');
            $one = $this->message($client, 'BUSY');
            $this->assertSame($mode, $one['phase']);
            $this->assertSame(1, $one['attempt']);
            $this->assertSame(0, $one['body_calls']);
            if ($exhaust) {
                $this->step($client, 'NEXT');
                $two = $this->message($client, 'BUSY');
                $this->assertSame(2, $two['attempt']);
                $this->step($client, 'NEXT');
                $last = $this->message($client, 'EXHAUSTED');
                $this->assertSame($mode, $last['phase']);
                $this->assertSame(3, $last['attempts']);
                $this->assertSame(0, $last['body_calls']);
                $this->assertCount(3, $last['busy']);
            }
            $this->step($block, 'RELEASE');
            $this->message($block, 'RELEASED');
            $this->finishWorker($block);
            $third = $this->lab->connect();
            $this->assertSame(0, $third->query('SELECT count(*) FROM main.b222_holds')->fetchColumn());
            $this->step($client, $exhaust ? 'RETRY' : 'NEXT');
            $done = $this->message($client, 'RESULT');
            $this->finishWorker($client);
            $this->assertSame(1, $done['body_calls']);
            $events = $mode === 'connect' ? $done['connection_busy'] : $done['busy'];
            $this->assertCount($exhaust ? 3 : 1, $events);
            $store = $this->lab->store($third);
            $hold = $store->forReservation(1);
            $this->assertSame($done['hold_id'], $hold->id);
            $this->assertCount(1, $store->all());
            $this->assertCount(1, $store->history($hold->id));
            $this->assertEquals($hold->acceptedAt->modify('+900 seconds'), $hold->expiresAt);
            $this->assertSame(0, $third->query("SELECT count(*) FROM main.b222_holds a JOIN main.b222_holds b ON a.hold_id<b.hold_id WHERE a.provider_profile_id=b.provider_profile_id AND a.state IN('held','committed') AND b.state IN('held','committed') AND a.starts_at_us<b.ends_at_us AND a.ends_at_us>b.starts_at_us")->fetchColumn());
            $this->assertSame('ok', $third->query('PRAGMA integrity_check')->fetchColumn());
            $this->assertSame([], $third->query('PRAGMA foreign_key_check')->fetchAll());
        } finally {
            $this->retainAndStop();
        }
    }
}
