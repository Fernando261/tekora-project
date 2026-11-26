<?php

namespace Tests\MySqlV81;

use App\Models\ServiceInquiry;
use App\Models\ServiceReservation;
use App\Models\User;
use App\Services\ReservationTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
        mysqlV81EnableBookingFixtureClock();
    }

    protected function bookingFixtureClock(?string $utc): void
    {
        $date = $utc === null ? null : CarbonImmutable::parse($utc, 'UTC');
        DB::statement('SET @tekora_v81_booking_us=?', [$date === null ? null : $date->getTimestamp() * 1000000 + (int) $date->micro]);
    }

    protected function migrateFoundation(): void
    {
        $this->artisan('migrate', ['--path' => mysqlV81MigrationPaths(), '--realpath' => true, '--force' => true])
            ->assertExitCode(0);
    }

    protected function reservationRow(ServiceInquiry $inquiry, string $time = '09:00', string $date = '2030-01-07', string $timezone = 'America/Mexico_City'): array
    {
        $slot = app(ReservationTime::class)->resolve($date, $time, $timezone);

        return ['service_inquiry_id' => $inquiry->id, 'service_id' => $inquiry->service_id,
            'provider_profile_id' => $inquiry->service->provider_profile_id, 'client_user_id' => $inquiry->client_user_id,
            'timezone' => $timezone, 'local_date' => $date, 'local_start_minute' => $slot['local_start_minute'],
            'starts_at_utc' => $slot['starts_at_utc'], 'ends_at_utc' => $slot['ends_at_utc'],
            'availability_revision' => 1, 'idempotency_key' => (string) Str::uuid(), 'status' => 'requested', 'version' => 1,
            'last_actor_user_id' => $inquiry->client_user_id, 'last_reason' => 'proposal',
            'created_at' => '2030-01-01 12:00:00', 'updated_at' => '2030-01-01 12:00:00'];
    }

    protected function assertMySqlError(int $code, callable $operation, ?string $diagnostic = null): void
    {
        try {
            $operation();
            $this->fail('Expected InnoDB to reject the statement.');
        } catch (QueryException $e) {
            $this->assertSame($code, (int) $e->errorInfo[1]);
            if ($diagnostic !== null) {
                $this->assertSame($diagnostic, $e->errorInfo[2]);
            }
        }
    }

    protected function completedReservation(ServiceInquiry $inquiry, User $provider, string $time = '09:00'): ServiceReservation
    {
        $row = $this->reservationRow($inquiry, $time);
        $id = DB::table('service_reservations')->insertGetId($row);
        $reservation = DB::table('service_reservations')->where('id', $id);
        $reservation->update(['status' => 'confirmed', 'version' => 2, 'last_actor_user_id' => $provider->id,
            'last_reason' => 'confirm', 'updated_at' => '2030-01-02 12:00:00']);
        $this->bookingFixtureClock($row['ends_at_utc']);
        try {
            $reservation->update(['status' => 'completed', 'version' => 3, 'last_actor_user_id' => $provider->id,
                'last_reason' => 'complete', 'updated_at' => $row['ends_at_utc']]);
        } finally {
            $this->bookingFixtureClock(null);
        }

        return ServiceReservation::findOrFail($id);
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
