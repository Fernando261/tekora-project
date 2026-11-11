<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use Tests\Support\Retired\Cita;
use Tests\Support\Retired\Profesionista;
use App\Models\ServiceReservation;
use App\Services\InquiryReadTracking;
use App\Services\ReservationWorkflow;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\Support\ReservationFixtures;
use Tests\TestCase;

class ReservationMigrationTest extends TestCase
{
    use ReservationFixtures;

    private function snapshot(array $tables): array
    {
        $rows = [];
        foreach ($tables as $table) $rows[$table] = ['columns' => Schema::getColumnListing($table), 'rows' => DB::table($table)->get()->map(fn ($r) => (array) $r)->sortBy(fn ($r) => json_encode($r))->values()->all()];

        return $rows;
    }

    public function test_upgrade_33_to_34_and_memory_only_reversal_preserve_all_previous_columns_and_records(): void
    {
        config(['database.connections.reservation_upgrade' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
        DB::setDefaultConnection('reservation_upgrade');
        $files = array_values(array_filter(glob(database_path('migrations/*.php')), fn ($file) => basename($file) < '2026_11_03_000000'));
        $this->assertCount(33, $files);
        $this->assertSame(0, Artisan::call('migrate', ['--database' => 'reservation_upgrade', '--path' => $files, '--realpath' => true, '--force' => true]));
        $this->assertDatabaseCount('migrations', 33);
        [$client, $provider, $inquiry] = $this->reservationContext(); $this->message($provider, $inquiry);
        app(InquiryReadTracking::class)->mark($client, $inquiry->id, $this->readToken($client, $inquiry));
        $legacy = Profesionista::create(['nombre' => 'Anterior', 'email' => $provider->email, 'cedula' => 'RESERVATION-UPGRADE',
            'password' => Hash::make('old-password'), 'google_access_token' => 'fake-preserved-access', 'google_refresh_token' => 'fake-preserved-refresh']);
        $cita = Cita::create(['user_id' => $client->id, 'profesionista_id' => $legacy->id, 'start_datetime' => '2030-01-07 10:00:00', 'end_datetime' => '2030-01-07 10:50:00',
            'starts_at' => '2030-01-07 10:00:00', 'ends_at' => '2030-01-07 10:50:00', 'estado' => 'pendiente', 'status' => 'pending', 'motivo' => 'Conservar cita']);
        DB::table('profesionista_bloqueos')->insert(['profesionista_id' => $legacy->id, 'start_datetime' => '2030-01-08 10:00:00', 'end_datetime' => '2030-01-08 11:00:00']);
        $tables = array_values(array_filter(array_column(Schema::getTables(), 'name'), fn ($t) => $t !== 'migrations'));
        $before = $this->snapshot($tables);
        // Conservar el escenario histórico 33→34; las reseñas se prueban por separado.
        $reservationFiles = array_values(array_filter(glob(database_path('migrations/*.php')), fn ($file) => basename($file) < '2026_11_03_000001'));
        $reservationOptions = ['--database' => 'reservation_upgrade', '--path' => $reservationFiles, '--realpath' => true, '--force' => true];
        $this->assertSame(0, Artisan::call('migrate', $reservationOptions));
        $this->assertDatabaseCount('migrations', 34); $this->assertDatabaseCount('service_reservations', 0); $this->assertDatabaseCount('reservation_events', 0);
        $this->assertSame($before, $this->snapshot($tables));
        $reservation = $this->proposeReservation($inquiry);
        $this->assertSame($inquiry->id, $reservation->inquiry->id); $this->assertSame($inquiry->service_id, $reservation->service->id);
        $this->assertSame($provider->providerProfile->id, $reservation->providerProfile->id); $this->assertSame($client->id, $reservation->client->id);
        $this->assertSame($reservation->id, $reservation->events()->sole()->reservation->id);
        $this->assertSame($legacy->id, $cita->fresh()->profesionista_id); $this->assertSame($before, $this->snapshot($tables));
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
        // Exclusivamente memoria: comprobar down() sin tocar ninguna base persistente.
        $this->assertSame(0, Artisan::call('migrate:rollback', ['--database' => 'reservation_upgrade', '--step' => 1, '--force' => true]));
        $this->assertDatabaseCount('migrations', 33); $this->assertFalse(Schema::hasTable('service_reservations')); $this->assertFalse(Schema::hasTable('reservation_events'));
        $this->assertSame($before, $this->snapshot($tables));
        $this->assertSame([], DB::select("SELECT name FROM sqlite_master WHERE type='trigger' AND (name LIKE 'reservation_%' OR name LIKE 'reservations_%')"));
        $this->assertSame(0, Artisan::call('migrate', $reservationOptions));
        $this->assertDatabaseCount('migrations', 34); $this->assertDatabaseCount('service_reservations', 0);
        $this->assertSame($before, $this->snapshot($tables));
    }

    public function test_database_enforces_identity_immutable_intervals_transitions_and_append_only_history(): void
    {
        [$client, $provider, $inquiry] = $this->reservationContext(); $reservation = $this->proposeReservation($inquiry);
        $other = $this->account(RoleName::Client, RoleName::Provider); $otherService = $this->service($other);
        foreach ([['client_user_id' => $other->id], ['service_id' => $otherService->id], ['provider_profile_id' => $other->providerProfile->id],
            ['service_inquiry_id' => 999999], ['starts_at_utc' => '2030-01-07 16:00:00'], ['local_start_minute' => 600], ['timezone' => 'UTC'],
            ['status' => 'completed', 'version' => 2], ['status' => 'confirmed', 'version' => 1], ['idempotency_key' => (string) \Illuminate\Support\Str::uuid()]] as $bad) {
            try { DB::table('service_reservations')->where('id', $reservation->id)->update($bad); $this->fail('SQL no debe alterar identidad, intervalo ni transición.'); }
            catch (QueryException $exception) { $this->assertSame('requested', $reservation->fresh()->status->value); }
        }
        foreach ([['service_inquiries', $inquiry->id, ['client_user_id' => $other->id]], ['services', $inquiry->service_id, ['provider_profile_id' => $other->providerProfile->id]],
            ['provider_profiles', $provider->providerProfile->id, ['user_id' => $other->id]]] as [$table, $id, $bad]) {
            try { DB::table($table)->where('id', $id)->update($bad); $this->fail('Relación de origen debe permanecer coherente.'); }
            catch (QueryException $exception) { $this->assertSame($client->id, $inquiry->fresh()->client_user_id); }
        }
        foreach (['service_reservations', 'reservation_events'] as $table) {
            try { DB::table($table)->delete(); $this->fail('No borrar historial.'); }
            catch (QueryException $exception) { $this->assertDatabaseCount($table, 1); }
        }
        try { DB::table('reservation_events')->update(['reason' => 'cancel']); $this->fail('Historial inmutable.'); }
        catch (QueryException $exception) { $this->assertSame('proposal', $reservation->events()->sole()->reason); }
        app(ReservationWorkflow::class)->decide($provider, $reservation->id, 'reject', ['version' => 1]);
        try { DB::table('service_reservations')->where('id', $reservation->id)->update(['status' => 'requested', 'version' => 3]); $this->fail('No reactivar terminales.'); }
        catch (QueryException $exception) { $this->assertSame('rejected', $reservation->fresh()->status->value); }
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
        $this->expectException(\Illuminate\Database\Eloquent\MassAssignmentException::class);
        new ServiceReservation(['client_user_id' => 999999]);
    }

    public function test_database_ranges_uniqueness_and_foreign_keys_reject_invalid_insertions(): void
    {
        [$client, , $inquiry] = $this->reservationContext(); $reservation = $this->proposeReservation($inquiry);
        $base = $reservation->getAttributes(); unset($base['id']);
        try { DB::table('service_reservations')->insert($base); $this->fail('Una sola reserva activa por solicitud.'); }
        catch (QueryException $exception) { $this->assertDatabaseCount('service_reservations', 1); }
        app(ReservationWorkflow::class)->decide($client, $reservation->id, 'cancel', ['version' => 1]);
        // Claves nuevas y sin ocupación activa: las pruebas siguientes no dependen de un choque de unicidad previo.
        foreach ([['local_start_minute' => 1380], ['local_start_minute' => 1.5], ['local_date' => '2030-02-30'],
            ['ends_at_utc' => '2030-01-07 17:00:00'], ['starts_at_utc' => '2030-01-07T15:00:00Z'], ['status' => 'invented'], ['service_inquiry_id' => 999999],
            ['last_actor_user_id' => 999999], ['version' => 0], ['availability_revision' => 0], ['created_at' => 'invalid'], ['updated_at' => '2030-02-30 12:00:00']] as $bad) {
            try { DB::table('service_reservations')->insert(array_replace($base, ['idempotency_key' => (string) \Illuminate\Support\Str::uuid()], $bad)); $this->fail('No aceptar duplicados ni datos inválidos.'); }
            catch (QueryException $exception) { $this->assertDatabaseCount('service_reservations', 1); }
        }
        $references = array_column(DB::select('PRAGMA foreign_key_list(service_reservations)'), 'table');
        $this->assertEqualsCanonicalizing(['users', 'users', 'provider_profiles', 'services', 'service_inquiries'], $references);
        $this->assertNotContains('profesionistas', $references); $this->assertNotContains('citas', $references);
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
    }
}
