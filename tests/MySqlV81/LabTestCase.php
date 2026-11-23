<?php

namespace Tests\MySqlV81;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\TestCase;
use Tests\Support\CatalogFixtures;

abstract class LabTestCase extends TestCase
{
    use CatalogFixtures;

    public function createApplication()
    {
        return mysqlV81Application();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateFoundation();
    }

    protected function migrateFoundation(): void
    {
        $this->artisan('migrate', ['--path' => mysqlV81MigrationPaths(), '--realpath' => true, '--force' => true])
            ->assertExitCode(0);
    }

    protected function assertMySqlError(int $code, callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected InnoDB to reject the statement.');
        } catch (QueryException $e) {
            $this->assertSame($code, (int) $e->errorInfo[1]);
        }
    }

    protected function startWorker(string $script, string|int ...$arguments): array
    {
        $process = proc_open([PHP_BINARY, __DIR__.'/'.$script, ...array_map(strval(...), $arguments)],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => STDERR], $pipes);
        $this->assertIsResource($process);
        stream_set_timeout($pipes[1], 15);
        $worker = [$process, $pipes];
        try {
            $this->assertSame("ready\n", fgets($pipes[1]));
        } catch (\Throwable $e) {
            $this->closeWorker($worker);
            throw $e;
        }

        return $worker;
    }

    protected function workerResult(array $worker): array
    {
        $line = fgets($worker[1][1]);
        $this->assertIsString($line);

        return json_decode($line, true, flags: JSON_THROW_ON_ERROR);
    }

    protected function closeWorker(array $worker): void
    {
        [$process, $pipes] = $worker;
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_terminate($process);
        proc_close($process);
    }
}
