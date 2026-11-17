<?php

namespace Tests\Feature;

use App\Enums\InquiryStatus;
use App\Enums\RoleName;
use App\Services\ReservationWorkflow;
use App\Support\BookingClock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\SlotHoldFixtures;
use Tests\TestCase;

class ReservationHoldConcurrencyTest extends TestCase
{
    use SlotHoldFixtures;

    public static function races(): array
    {
        return ['two services overlapping' => ['overlap'], 'adjacent slots' => ['adjacent'], 'independent providers' => ['independent'],
            'legacy versus quoted' => ['legacy'], 'two client confirmations' => ['commit'], 'same idempotent confirmation' => ['retry'], 'expiry versus new acceptance' => ['expiry']];
    }

    #[DataProvider('races')]
    public function test_independent_processes_share_one_booking_authority(string $mode): void
    {
        [$client, $provider, $inquiry, $quote, $one] = $this->pricedProposal();
        $wf = app(ReservationWorkflow::class);
        if (in_array($mode, ['commit', 'retry'], true)) {
            $wf->decide($provider, $one->id, 'confirm', $this->holdInput());
            $two = $one;
            $actors = [$client, $client];
            $actions = ['commit', 'commit'];
        } else {
            $otherClient = $this->account(RoleName::Client);
            if ($mode === 'legacy') {
                $otherInquiry = $this->inquiry($otherClient, $provider, InquiryStatus::Accepted);
                $two = $this->proposeReservation($otherInquiry);
            } else {
                [, $otherProvider, , , $two] = $this->pricedProposal($otherClient, $mode === 'independent' ? $this->account(RoleName::Provider) : $provider,
                    ['time' => $mode === 'adjacent' ? '10:00' : '09:30']);
            }
            $actors = [$provider, $mode === 'independent' ? $otherProvider : $provider];
            $actions = ['confirm', 'confirm'];
            if ($mode === 'expiry') {
                $wf->decide($provider, $one->id, 'confirm', $this->holdInput());
                $this->travel(900)->seconds();
                $actors[0] = $client;
                $actions[0] = 'commit';
            }
        }
        $root = sys_get_temp_dir().'/tekora-hold-race-'.bin2hex(random_bytes(8));
        mkdir($root, 0700);
        $root = realpath($root) ?: throw new \RuntimeException('Ruta sintética no canónica.');
        file_put_contents($root.'/synthetic-only', 'Disposable synthetic test database');
        $path = $root.'/synthetic.sqlite';
        DB::connection()->getPdo()->exec('VACUUM INTO '.DB::connection()->getPdo()->quote($path));
        $processes = [];
        try {
            $commonKey = (string) Str::uuid();
            foreach ([1 => $one, 2 => $two] as $worker => $reservation) {
                $process = proc_open([PHP_BINARY, base_path('tests/Support/ReservationHoldWorker.php'), $root, (string) $worker,
                    (string) $actors[$worker - 1]->id, (string) $reservation->id, $actions[$worker - 1], $mode === 'retry' ? $commonKey : (string) Str::uuid(), now('UTC')->format('Y-m-d H:i:s.u')],
                    [0 => ['pipe', 'r'], 1 => ['file', $root.'/stdout-'.$worker, 'w'], 2 => ['file', $root.'/stderr-'.$worker, 'w']], $pipes, base_path());
                $this->assertIsResource($process);
                fclose($pipes[0]);
                $processes[$worker] = $process;
            }
            $deadline = microtime(true) + 10;
            while (! is_file($root.'/ready-1') || ! is_file($root.'/ready-2')) {
                if (microtime(true) > $deadline) {
                    $this->fail('Ambos procesos deben competir: '.file_get_contents($root.'/stderr-1').file_get_contents($root.'/stderr-2'));
                }
                usleep(1000);
            }
            file_put_contents($root.'/go', 'Both independent workers admitted');
            $results = [];
            foreach ($processes as $worker => $process) {
                $code = proc_close($process);
                unset($processes[$worker]);
                $this->assertSame(0, $code, file_get_contents($root.'/stderr-'.$worker));
                $results[] = json_decode(file_get_contents($root.'/stdout-'.$worker), true, flags: JSON_THROW_ON_ERROR);
            }
            $this->assertNotSame($results[0]['pid'], $results[1]['pid']);
            $outcomes = array_column($results, 'outcome');
            sort($outcomes);
            if (in_array($mode, ['adjacent', 'independent'], true)) {
                $this->assertSame(['held', 'held'], $outcomes);
            } elseif ($mode === 'retry') {
                $this->assertSame(['confirmed', 'confirmed'], $outcomes);
            } else {
                $this->assertContains('conflict', $outcomes);
                $this->assertCount(1, array_filter($outcomes, fn ($v) => in_array($v, ['held', 'confirmed'], true)));
                if ($mode === 'expiry') {
                    $this->assertSame('conflict', $results[0]['outcome']);
                }
            }
            // A NEW independent connection observes fully committed effects after both exits.
            $reader = new PDO('sqlite:'.$path);
            $reader->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $reader->exec('PRAGMA foreign_keys=ON');
            BookingClock::install($reader);
            $expected = in_array($mode, ['adjacent', 'independent'], true) ? 2 : 1;
            $this->assertSame($expected, (int) $reader->query('SELECT count(*) FROM reservation_occupancy')->fetchColumn());
            $this->assertSame(0, (int) $reader->query('SELECT count(*) FROM reservation_occupancy a JOIN reservation_occupancy b ON a.reservation_id<b.reservation_id AND a.provider_profile_id=b.provider_profile_id AND a.starts_at_utc<b.ends_at_utc AND a.ends_at_utc>b.starts_at_utc')->fetchColumn());
            if (in_array($mode, ['commit', 'retry'], true)) {
                $this->assertSame(2, (int) $reader->query('SELECT count(*) FROM reservation_events')->fetchColumn());
                $this->assertSame(2, (int) $reader->query('SELECT count(*) FROM reservation_hold_events')->fetchColumn());
                $this->assertSame(2, (int) $reader->query('SELECT count(*) FROM reservation_action_receipts')->fetchColumn());
            }
            if ($mode === 'expiry') {
                $this->assertSame('expired', $reader->query('SELECT state FROM reservation_holds WHERE service_reservation_id='.(int) $one->id)->fetchColumn());
            }
            $this->assertSame(1, (int) $reader->query('PRAGMA foreign_keys')->fetchColumn());
            $this->assertSame('ok', $reader->query('PRAGMA integrity_check')->fetchColumn());
            $this->assertSame([], $reader->query('PRAGMA foreign_key_check')->fetchAll());
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
