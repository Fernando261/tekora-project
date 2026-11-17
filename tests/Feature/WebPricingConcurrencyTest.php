<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CatalogFixtures;
use Tests\TestCase;

class WebPricingConcurrencyTest extends TestCase
{
    use CatalogFixtures;

    public static function races(): array
    {
        return ['competing updates' => [false], 'same idempotent request' => [true]];
    }

    #[DataProvider('races')]
    public function test_two_real_processes_create_only_one_price(bool $sameKey): void
    {
        $provider = $this->account(RoleName::Provider);
        $service = $this->service($provider);
        $root = sys_get_temp_dir().'/tekora-pricing-race-'.bin2hex(random_bytes(8));
        mkdir($root, 0700);
        $root = realpath($root) ?: throw new \RuntimeException('No se pudo canonizar el directorio sintético.');
        file_put_contents($root.'/synthetic-only', 'Disposable synthetic test');
        $path = $root.'/synthetic.sqlite';
        DB::connection()->getPdo()->exec('VACUUM INTO '.DB::connection()->getPdo()->quote($path));
        $processes = [];
        $commonKey = (string) Str::uuid();
        try {
            foreach ([1, 2] as $worker) {
                $process = proc_open([PHP_BINARY, base_path('tests/Support/WebPricingWorker.php'), $root, (string) $provider->id, (string) $service->id, $sameKey ? $commonKey : (string) Str::uuid(), (string) $worker], [0 => ['pipe', 'r'], 1 => ['file', $root.'/stdout-'.$worker, 'w'], 2 => ['file', $root.'/stderr-'.$worker, 'w']], $pipes, base_path());
                $this->assertIsResource($process);
                fclose($pipes[0]);
                $processes[$worker] = $process;
            }
            $deadline = microtime(true) + 10;
            while (! is_file($root.'/ready-1') || ! is_file($root.'/ready-2')) {
                if (microtime(true) > $deadline) {
                    $this->fail('Ambos procesos deben llegar a la barrera antes de competir: '.file_get_contents($root.'/stderr-1').file_get_contents($root.'/stderr-2'));
                }
                usleep(1000);
            }
            file_put_contents($root.'/go', 'both workers admitted');
            $outcomes = [];
            $ids = [];
            foreach ($processes as $worker => $process) {
                $code = proc_close($process);
                unset($processes[$worker]);
                $this->assertSame(0, $code, file_get_contents($root.'/stderr-'.$worker));
                $result = json_decode(file_get_contents($root.'/stdout-'.$worker), true, flags: JSON_THROW_ON_ERROR);
                $outcomes[] = $result['outcome'];
                if (isset($result['id'])) {
                    $ids[] = $result['id'];
                }
            }
            sort($outcomes);
            $this->assertSame($sameKey ? ['created', 'created'] : ['conflict', 'created'], $outcomes);
            $this->assertCount(1, array_unique($ids));
            // Independent reader, after BOTH processes have exited.
            $reader = new PDO('sqlite:'.$path);
            $reader->exec('PRAGMA foreign_keys=ON');
            $this->assertSame(1, (int) $reader->query('SELECT count(*) FROM service_price_versions')->fetchColumn());
            $this->assertSame(1, (int) $reader->query('SELECT version FROM service_price_versions')->fetchColumn());
            $this->assertSame(1, (int) $reader->query('PRAGMA foreign_keys')->fetchColumn());
            $this->assertSame('ok', $reader->query('PRAGMA integrity_check')->fetchColumn());
            $this->assertSame([], $reader->query('PRAGMA foreign_key_check')->fetchAll());
            $this->assertSame(0, (int) $reader->query('SELECT count(*) FROM service_quotes')->fetchColumn());
            $reader = null;
        } finally {
            foreach ($processes as $process) {
                proc_terminate($process);
                proc_close($process);
            }
            foreach (glob($root.'/*') as $file) {
                unlink($file);
            }
            rmdir($root);
        }
    }
}
