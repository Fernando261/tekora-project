<?php

namespace Tests\Feature;

use App\Enums\InquiryStatus;
use App\Payments\ServicePricing;
use App\Payments\SyntheticQuotes;
use App\Support\BookingClock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\MobileOperationsFixtures;
use Tests\TestCase;

class MobileQuoteConcurrencyTest extends TestCase
{
    use MobileOperationsFixtures;

    public static function races(): array
    {
        return [['issue'], ['accept'], ['conflicting_key']];
    }

    #[DataProvider('races')]
    public function test_two_real_api_processes_serialize_issuance_acceptance_and_key_conflicts(string $mode): void
    {
        [$client, $provider, $inquiry] = $this->reservationContext();
        app(ServicePricing::class)->publish($provider, $inquiry->service_id, ['amount_cents' => 100000, 'expected_version' => 0, 'idempotency_key' => (string) Str::uuid()]);
        $key = (string) Str::uuid();
        $one = ['path' => '/api/v1/inquiries/'.$inquiry->id.'/quotes', 'body' => ['idempotency_key' => $key]];
        $two = $one;
        if ($mode === 'accept') {
            $quote = app(SyntheticQuotes::class)->issue($client, $inquiry->id, ['idempotency_key' => $key]);
            $one = $two = ['path' => '/api/v1/quotes/'.$quote->id.'/accept', 'body' => ['acknowledge' => true]];
        } elseif ($mode === 'conflicting_key') {
            $other = $this->inquiry($client, $provider, InquiryStatus::Accepted);
            app(ServicePricing::class)->publish($provider, $other->service_id, ['amount_cents' => 200000, 'expected_version' => 0, 'idempotency_key' => (string) Str::uuid()]);
            $two['path'] = '/api/v1/inquiries/'.$other->id.'/quotes';
        }
        $token = $client->createToken('mobile:B3-race', ['client:read'], now()->addDay())->plainTextToken;
        $root = sys_get_temp_dir().'/tekora-b3-race-'.bin2hex(random_bytes(8));
        mkdir($root, 0700);
        $root = realpath($root);
        file_put_contents($root.'/synthetic-only', 'New synthetic B3 test database');
        file_put_contents($root.'/private.json', json_encode(['token' => $token, 'clock' => now('UTC')->format('Y-m-d H:i:s.u'), 'workers' => [1 => $one, 2 => $two]]));
        chmod($root.'/private.json', 0600);
        $path = $root.'/synthetic.sqlite';
        DB::connection()->getPdo()->exec('VACUUM INTO '.DB::connection()->getPdo()->quote($path));
        $processes = [];
        try {
            foreach ([1, 2] as $worker) {
                $process = proc_open([PHP_BINARY, base_path('tests/Support/CommercialApiWorker.php'), $root, (string) $worker],
                    [0 => ['pipe', 'r'], 1 => ['file', $root.'/stdout-'.$worker, 'w'], 2 => ['file', $root.'/stderr-'.$worker, 'w']], $pipes, base_path());
                $this->assertIsResource($process);
                fclose($pipes[0]);
                $processes[$worker] = $process;
            }
            $deadline = microtime(true) + 10;
            while (! is_file($root.'/ready-1') || ! is_file($root.'/ready-2')) {
                if (microtime(true) > $deadline) {
                    $this->fail('Both independent API workers must reach the barrier.');
                }
                usleep(1000);
            }
            file_put_contents($root.'/go', 'Simultaneous release');
            $results = [];
            foreach ($processes as $worker => $process) {
                $code = proc_close($process);
                unset($processes[$worker]);
                $this->assertSame(0, $code, file_get_contents($root.'/stderr-'.$worker));
                $results[] = json_decode(file_get_contents($root.'/stdout-'.$worker), true, flags: JSON_THROW_ON_ERROR);
            }
            $this->assertNotSame($results[0]['pid'], $results[1]['pid']);
            $statuses = array_column($results, 'status');
            sort($statuses);
            $this->assertSame($mode === 'conflicting_key' ? [200, 409] : [200, 200], $statuses, json_encode($results));
            if ($mode !== 'conflicting_key') {
                $this->assertSame($results[0]['json']['data']['id'], $results[1]['json']['data']['id']);
            }
            $reader = new PDO('sqlite:'.$path);
            $reader->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $reader->exec('PRAGMA foreign_keys=ON');
            BookingClock::install($reader);
            $this->assertSame(1, (int) $reader->query('SELECT count(*) FROM service_quotes')->fetchColumn());
            $this->assertSame($mode === 'accept' ? 1 : 0, (int) $reader->query('SELECT count(*) FROM quote_acceptances')->fetchColumn());
            $this->assertSame(0, (int) $reader->query('SELECT count(*) FROM service_reservations')->fetchColumn());
            $this->assertSame('ok', $reader->query('PRAGMA integrity_check')->fetchColumn());
            $this->assertSame([], $reader->query('PRAGMA foreign_key_check')->fetchAll());
            $this->assertSame(1, (int) $reader->query('PRAGMA foreign_keys')->fetchColumn());
            if ($mode === 'accept') {
                $this->assertSame($results[0]['json']['data']['acceptance']['accepted_at'], $results[1]['json']['data']['acceptance']['accepted_at']);
            }
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
