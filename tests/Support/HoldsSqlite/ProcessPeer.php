<?php

declare(strict_types=1);

namespace Tests\Support\HoldsSqlite;

use RuntimeException;

/** Two real PHP CLI processes communicate only via bounded pipes, never a shared PDO. */
final class ProcessPeer
{
    private mixed $process;

    private array $pipes = [];

    private string $buffer = '';

    public array $trace = [];

    public string $stderr = '';

    public function __construct(array $config)
    {
        $this->process = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', __DIR__.'/HoldWorker.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $this->pipes, options: ['bypass_shell' => true]);
        if (! is_resource($this->process)) {
            throw new RuntimeException('Independent PHP worker unavailable.');
        }
        stream_set_blocking($this->pipes[1], false);
        stream_set_blocking($this->pipes[2], false);
        $this->send($config);
    }

    private function send(array $message): void
    {
        $line = json_encode($message, JSON_THROW_ON_ERROR)."\n";
        if (fwrite($this->pipes[0], $line) !== strlen($line)) {
            throw new RuntimeException('Worker pipe incomplete.');
        }
        fflush($this->pipes[0]);
    }

    public function command(string $phase): void
    {
        $this->send(['phase' => $phase]);
        $this->trace[] = ['sent' => $phase];
    }

    public function receive(string $expected): array
    {
        $deadline = hrtime(true) + 10000000000;
        while (! str_contains($this->buffer, "\n")) {
            if (hrtime(true) >= $deadline) {
                throw new RuntimeException('Worker barrier timeout: '.$expected.' '.stream_get_contents($this->pipes[2]));
            }
            $read = [$this->pipes[1]];
            $write = null;
            $except = null;
            $ready = stream_select($read, $write, $except, 0, 100000);
            if ($ready === false) {
                throw new RuntimeException('Worker select failed.');
            }
            if ($ready > 0) {
                $chunk = stream_get_contents($this->pipes[1]);
                if ($chunk === '' && feof($this->pipes[1])) {
                    throw new RuntimeException('Worker exited before '.$expected.' '.stream_get_contents($this->pipes[2]));
                }
                $this->buffer .= $chunk;
            }
        }
        [$line,$this->buffer] = explode("\n", $this->buffer, 2);
        try {
            $value = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new RuntimeException('Non-JSON worker output: '.json_encode($line).' buffered='.json_encode($this->buffer).' stderr='.stream_get_contents($this->pipes[2]), previous: $e);
        }
        $this->trace[] = $value;
        if (($value['phase'] ?? null) !== $expected) {
            throw new RuntimeException('Expected '.$expected.': '.$line);
        }

        return $value;
    }

    public function finish(): int
    {
        $deadline = hrtime(true) + 5000000000;
        do {
            $status = proc_get_status($this->process);
            if (! $status['running']) {
                $this->stderr .= stream_get_contents($this->pipes[2]);
                foreach ($this->pipes as $pipe) {
                    fclose($pipe);
                }
                $code = proc_close($this->process);
                $this->process = null;

                return $code === -1 ? $status['exitcode'] : $code;
            }
            usleep(1000);
        } while (hrtime(true) < $deadline);
        throw new RuntimeException('Worker exit timeout.');
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            $status = proc_get_status($this->process);
            if ($status['running']) {
                proc_terminate($this->process);
            }
            foreach ($this->pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_close($this->process);
            $this->process = null;
        }
    }

    public function __destruct()
    {
        $this->stop();
    }
}
