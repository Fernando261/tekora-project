<?php

declare(strict_types=1);

namespace Tests\Support\PaymentsIntegration;

/** Always exec a fresh PHP runtime. Never run PHP callbacks after a raw fork. */
final class ProcessPeer
{
    private mixed $process = null;

    private array $pipes = [];

    private string $stdout = '';

    private string $stderr = '';

    public readonly int $pid;

    public readonly array $startup;

    public ?array $completion = null;

    public ?int $exitCode = null;

    public array $trace = [];

    public function __construct(array $config)
    {
        ProcessRuntime::assertExecSafeDescriptors();
        // Array command bypasses the shell. The exec boundary discards PHP objects;
        // the worker attests zero SQLite descriptors before opening its own PDO.
        $this->process = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', __DIR__.'/IntegrationWorker.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $this->pipes);
        if (! is_resource($this->process)) {
            throw new \RuntimeException('PHP worker unavailable');
        }
        $this->pid = proc_get_status($this->process)['pid'];
        stream_set_blocking($this->pipes[1], false);
        stream_set_blocking($this->pipes[2], false);
        $this->send(CommandEnvelope::json(array_merge($config, ['parent_pid' => getmypid()])));
        $this->startup = $this->read();
        if (($this->startup['phase'] ?? null) !== 'started' || ($this->startup['protocol'] ?? null) !== 'b231-exec-v1' || $this->startup['pid'] !== $this->pid || $this->pid === getmypid() || $this->startup['sqlite_descriptors'] !== 0) {
            throw new \RuntimeException('Fresh worker attestation failed');
        }
    }

    public function send(string $line): void
    {
        $data = $line."\n";
        while ($data !== '') {
            $written = fwrite($this->pipes[0], $data);
            if ($written === false || $written === 0) {
                throw new \RuntimeException('Worker input unavailable');
            }
            $data = substr($data, $written);
        }
        fflush($this->pipes[0]);
    }

    private function status(): array
    {
        $status = proc_get_status($this->process);
        if (! $status['running'] && $status['exitcode'] >= 0) {
            $this->exitCode = $status['exitcode'];
        }

        return $status;
    }

    private function pump(): void
    {
        $read = array_values(array_filter([$this->pipes[1], $this->pipes[2]], fn ($p) => ! feof($p)));
        if ($read === []) {
            return;
        }
        $write = null;
        $except = null;
        stream_select($read, $write, $except, 0, 100000);
        foreach ($read as $p) {
            $data = stream_get_contents($p);
            if ($p === $this->pipes[1]) {
                $this->stdout .= $data;
            } else {
                $this->stderr .= $data;
            }
        }
    }

    public function read(): array
    {
        $deadline = microtime(true) + 15;
        while (microtime(true) < $deadline) {
            $newline = strpos($this->stdout, "\n");
            if ($newline !== false) {
                $line = substr($this->stdout, 0, $newline);
                $this->stdout = substr($this->stdout, $newline + 1);
                $r = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                $this->trace[] = $r;
                if (($r['phase'] ?? null) === 'closed') {
                    $this->completion = $r;
                }

                return $r;
            }
            $this->pump();
            if (! $this->status()['running'] && ! str_contains($this->stdout, "\n")) {
                throw new \RuntimeException('Worker exited without message: '.$this->stderr);
            }
        }
        throw new \RuntimeException('Worker barrier timed out; resources retained: '.$this->stderr);
    }

    public function close(): void
    {
        if (! is_resource($this->process)) {
            return;
        }
        if (is_resource($this->pipes[0])) {
            // EOF releases idle/retry/release barriers; the worker rolls back in finally.
            fclose($this->pipes[0]);
        }
        while ($this->completion === null) {
            $message = $this->read();
            if (($message['phase'] ?? null) === 'closed') {
                $this->completion = $message;
            }
        }
        $deadline = microtime(true) + 15;
        while ($this->status()['running']) {
            if (microtime(true) >= $deadline) {
                throw new \RuntimeException('Worker did not exit normally; no termination sent');
            }
            usleep(1000);
        }
        foreach ([1, 2] as $i) {
            fclose($this->pipes[$i]);
        }
        $exit = proc_close($this->process);
        $this->exitCode ??= $exit;
        $this->process = null;
        if ($this->completion['pid'] !== $this->pid || ! $this->completion['clean'] || $this->completion['sqlite_descriptors'] !== 0 || ! in_array($this->exitCode, [0, 1], true) || $this->stderr !== '') {
            throw new \RuntimeException('Worker cleanup not established: '.$this->stderr);
        }
    }

    public function __destruct()
    {
        if (is_resource($this->process)) {
            $this->close();
        }
    }
}
