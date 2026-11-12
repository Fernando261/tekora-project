<?php

declare(strict_types=1);

namespace Tests\Support\PaymentsIntegration;

final class ProcessPeer
{
    private mixed $process;

    private array $pipes = [];

    public array $trace = [];

    public function __construct(array $config)
    {
        $this->process = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', __DIR__.'/IntegrationWorker.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $this->pipes);
        if (! is_resource($this->process)) {
            throw new \RuntimeException('PHP worker unavailable');
        }
        stream_set_blocking($this->pipes[1], false);
        stream_set_blocking($this->pipes[2], false);
        $this->send(CommandEnvelope::json($config));
    }

    public function send(string $line): void
    {
        fwrite($this->pipes[0], $line."\n");
        fflush($this->pipes[0]);
    }

    public function read(): array
    {
        $deadline = microtime(true) + 15;
        $line = '';
        $stderr = '';
        while (microtime(true) < $deadline) {
            $read = [$this->pipes[1], $this->pipes[2]];
            $write = null;
            $except = null;
            stream_select($read, $write, $except, 0, 100000);
            foreach ($read as $p) {
                if ($p === $this->pipes[2]) {
                    $stderr .= stream_get_contents($p);
                } else {
                    $chunk = fgets($p);
                    if ($chunk !== false) {
                        $line .= $chunk;
                    }
                }
            }
            if (str_contains($line, "\n")) {
                $r = json_decode(trim($line), true, flags: JSON_THROW_ON_ERROR);
                $this->trace[] = $r;

                return $r;
            }
            if (! proc_get_status($this->process)['running']) {
                throw new \RuntimeException('Worker exited: '.$stderr.' '.$line);
            }
        }
        throw new \RuntimeException('Worker barrier timed out: '.$stderr);
    }

    public function close(): void
    {
        if (! is_resource($this->process)) {
            return;
        }
        fclose($this->pipes[0]);
        if (proc_get_status($this->process)['running']) {
            proc_terminate($this->process);
        }
        foreach ([1, 2] as $i) {
            fclose($this->pipes[$i]);
        }proc_close($this->process);
        $this->process = null;
    }

    public function __destruct()
    {
        $this->close();
    }
}
