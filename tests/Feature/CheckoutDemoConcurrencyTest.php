<?php

namespace Tests\Feature;

use Illuminate\Support\Str;
use Tests\Support\CheckoutDemoFixtures;
use Tests\TestCase;

class CheckoutDemoConcurrencyTest extends TestCase
{
    use CheckoutDemoFixtures;

    public function test_two_independent_processes_replay_one_server_attempt_and_one_internal_receipt(): void
    {
        $this->prepareDemo();
        [$demo, $credential] = $this->customer();
        $root = sys_get_temp_dir().'/tekora-demo-race-'.bin2hex(random_bytes(8));
        mkdir($root, 0700);
        $root = realpath($root);
        file_put_contents($root.'/input.json', json_encode(['root' => $this->demoRoot, 'credential' => $credential, 'now' => now('UTC')->format('Y-m-d H:i:s.u'),
            'body' => ['method' => 'card', 'instrument' => 'debit', 'scenario' => 'success', 'idempotency_key' => (string) Str::uuid()]]));
        chmod($root.'/input.json', 0600);
        $lock = fopen($this->demoRoot.'/contexts/'.$demo['id'].'.json', 'r+b');
        flock($lock, LOCK_EX);
        $processes = [];
        try {
            foreach ([1, 2] as $worker) {
                $process = proc_open([PHP_BINARY, base_path('tests/Support/CheckoutDemoWorker.php'), $root, (string) $worker],
                    [0 => ['pipe', 'r'], 1 => ['file', $root.'/result-'.$worker, 'w'], 2 => ['file', $root.'/error-'.$worker, 'w']], $pipes, base_path());
                $this->assertIsResource($process);
                fclose($pipes[0]);
                $processes[$worker] = $process;
            }
            $deadline = microtime(true) + 10;
            while (! is_file($root.'/ready-1') || ! is_file($root.'/ready-2')) {
                if (microtime(true) > $deadline) {
                    $this->fail('Workers did not reach the barrier.');
                } usleep(1000);
            }
            file_put_contents($root.'/go', 'start');
            while (! is_file($root.'/entering-1') || ! is_file($root.'/entering-2')) {
                if (microtime(true) > $deadline) {
                    $this->fail('Workers did not enter.');
                } usleep(1000);
            }
            $this->assertSame('', file_get_contents($root.'/result-1'));
            $this->assertSame('', file_get_contents($root.'/result-2'));
            flock($lock, LOCK_UN);
            fclose($lock);
            $lock = null;
            $results = [];
            foreach ($processes as $worker => $process) {
                $this->assertSame(0, proc_close($process), file_get_contents($root.'/error-'.$worker));
                unset($processes[$worker]);
                $results[] = json_decode(file_get_contents($root.'/result-'.$worker), true, flags: JSON_THROW_ON_ERROR);
            }
            $this->assertNotSame($results[0]['pid'], $results[1]['pid']);
            $this->assertSame($results[0]['attempt'], $results[1]['attempt']);
            $state = json_decode(file_get_contents($this->demoRoot.'/contexts/'.$demo['id'].'.json'), true);
            $this->assertCount(1, $state['attempts']);
            $this->assertTrue($state['attempts'][0]['receipt']['demo_only']);
        } finally {
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
            foreach ($processes as $process) {
                proc_terminate($process);
                proc_close($process);
            }
            foreach (glob($root.'/*') as $path) {
                unlink($path);
            } rmdir($root);
        }
    }
}
