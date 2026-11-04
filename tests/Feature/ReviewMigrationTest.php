<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\Cita;
use App\Models\Profesionista;
use App\Models\ServiceReview;
use App\Services\InquiryReadTracking;
use App\Services\ReviewWorkflow;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\Support\ReviewFixtures;
use Tests\TestCase;

class ReviewMigrationTest extends TestCase
{
    use ReviewFixtures;

    private function snapshot(array $tables): array
    {
        $snapshot = [];
        foreach ($tables as $table) $snapshot[$table] = ['columns' => Schema::getColumnListing($table), 'rows' => DB::table($table)->get()->map(fn ($r) => (array) $r)->sortBy(fn ($r) => json_encode($r))->values()->all()];

        return $snapshot;
    }

    public function test_upgrade_34_to_35_and_memory_only_reversal_preserve_every_previous_table_and_row(): void
    {
        config(['database.connections.review_upgrade' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
        DB::setDefaultConnection('review_upgrade');
        $files = array_values(array_filter(glob(database_path('migrations/*.php')), fn ($file) => basename($file) < '2026_11_03_000001'));
        $this->assertCount(34, $files);
        $this->assertSame(0, Artisan::call('migrate', ['--database' => 'review_upgrade', '--path' => $files, '--realpath' => true, '--force' => true]));
        $this->assertDatabaseCount('migrations', 34);
        [$client, $provider, $inquiry, $reservation] = $this->reviewContext(); $this->message($provider, $inquiry);
        app(InquiryReadTracking::class)->mark($client, $inquiry->id, $this->readToken($client, $inquiry));
        $legacy = Profesionista::create(['nombre' => 'Profesional anterior', 'email' => $provider->email, 'cedula' => 'REVIEW-UPGRADE', 'password' => Hash::make('old-separate-password'),
            'google_access_token' => 'fake-preserved-access', 'google_refresh_token' => 'fake-preserved-refresh']);
        $cita = Cita::create(['user_id' => $client->id, 'profesionista_id' => $legacy->id, 'start_datetime' => '2030-01-07 10:00:00', 'end_datetime' => '2030-01-07 10:50:00',
            'starts_at' => '2030-01-07 10:00:00', 'ends_at' => '2030-01-07 10:50:00', 'estado' => 'pendiente', 'status' => 'pending', 'motivo' => 'Conservar cita']);
        DB::table('sessions')->insert(['id' => 'review-existing-session', 'user_id' => $client->id, 'payload' => 'opaque-fixture', 'last_activity' => 1]);
        DB::table('profesionista_disponibilidades')->insert(['profesionista_id' => $legacy->id, 'day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '18:00', 'is_active' => 1]);
        DB::table('profesionista_bloqueos')->insert(['profesionista_id' => $legacy->id, 'start_datetime' => '2030-01-08 10:00:00', 'end_datetime' => '2030-01-08 11:00:00']);
        $tables = array_values(array_filter(array_column(Schema::getTables(), 'name'), fn ($t) => $t !== 'migrations'));
        $before = $this->snapshot($tables);
        // Mantener el escenario histórico 34→35; la actividad se prueba por separado.
        $reviewFiles = array_values(array_filter(glob(database_path('migrations/*.php')), fn ($file) => basename($file) < '2026_11_03_000002'));
        $reviewOptions = ['--database' => 'review_upgrade', '--path' => $reviewFiles, '--realpath' => true, '--force' => true];
        $this->assertSame(0, Artisan::call('migrate', $reviewOptions));
        $this->assertDatabaseCount('migrations', 35); $this->assertDatabaseCount('service_reviews', 0); $this->assertSame($before, $this->snapshot($tables));
        $review = $this->publishReview($reservation);
        $this->assertSame($reservation->id, $review->reservation->id); $this->assertSame($reservation->id, $reservation->review->service_reservation_id);
        $this->assertSame($client->id, $review->client->id); $this->assertSame($inquiry->service_id, $review->service->id);
        $this->assertSame($provider->providerProfile->id, $review->providerProfile->id); $this->assertSame($review->id, $inquiry->service->reviews()->sole()->id);
        $this->assertSame($review->id, $provider->providerProfile->reviews()->sole()->id);
        app(ReviewWorkflow::class)->withdraw($client, $review->id);
        $this->assertSame($legacy->id, $cita->fresh()->profesionista->id); $this->assertSame($before, $this->snapshot($tables));
        $this->assertSame([], DB::select('PRAGMA foreign_key_check')); $this->assertSame('ok', DB::select('PRAGMA integrity_check')[0]->integrity_check);
        // Solo memoria: revertir la migración nueva, nunca una instalación persistente.
        $this->assertSame(0, Artisan::call('migrate:rollback', ['--database' => 'review_upgrade', '--step' => 1, '--force' => true]));
        $this->assertDatabaseCount('migrations', 34); $this->assertFalse(Schema::hasTable('service_reviews')); $this->assertSame($before, $this->snapshot($tables));
        $this->assertSame([], DB::select("SELECT name FROM sqlite_master WHERE type='trigger' AND name LIKE 'reviews_%'"));
        $this->assertSame(0, Artisan::call('migrate', $reviewOptions));
        $this->assertDatabaseCount('migrations', 35); $this->assertDatabaseCount('service_reviews', 0); $this->assertSame($before, $this->snapshot($tables));
    }

    public function test_database_rejects_invalid_ratings_comments_dates_and_inconsistent_central_references(): void
    {
        [$client, $provider, $inquiry, $reservation] = $this->reviewContext();
        $row = ['service_reservation_id' => $reservation->id, 'service_id' => $reservation->service_id, 'provider_profile_id' => $reservation->provider_profile_id,
            'client_user_id' => $client->id, 'rating' => 5, 'comment' => null, 'status' => 'published', 'created_at' => now('UTC')->format('Y-m-d H:i:s')];
        $stranger = $this->account(RoleName::Client, RoleName::Provider); $otherService = $this->service($stranger);
        foreach ([['rating' => 0], ['rating' => 6], ['rating' => 3.5], ['comment' => str_repeat('x', 1001)], ['comment' => "nul\0oculto"],
            ['client_user_id' => $stranger->id], ['client_user_id' => $provider->id], ['service_id' => $otherService->id], ['provider_profile_id' => $stranger->providerProfile->id],
            ['service_reservation_id' => 999999], ['created_at' => '2030-02-30 00:00:00'], ['created_at' => '2029-01-01 00:00:00'], ['status' => 'invented'],
            ['status' => 'withdrawn', 'withdrawn_at_utc' => now('UTC')->format('Y-m-d H:i:s'), 'withdrawn_by_user_id' => $client->id]] as $bad) {
            try { DB::table('service_reviews')->insert(array_replace($row, $bad)); $this->fail('CHECKs/triggers deben proteger también SQL directo.'); }
            catch (QueryException $exception) { $this->assertDatabaseCount('service_reviews', 0); }
        }
        $pending = $this->proposeReservation($inquiry, ['date' => '2030-01-08']);
        try { DB::table('service_reviews')->insert(array_replace($row, ['service_reservation_id' => $pending->id])); $this->fail('No reseñar una reserva sin completar.'); }
        catch (QueryException $exception) { $this->assertDatabaseCount('service_reviews', 0); }
        DB::table('service_reviews')->insert($row);
        $this->assertDatabaseCount('service_reviews', 1); $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
        $keys = array_column(DB::select('PRAGMA foreign_key_list(service_reviews)'), 'table');
        $this->assertEqualsCanonicalizing(['users', 'users', 'provider_profiles', 'services', 'service_reservations'], $keys);
        $this->assertNotContains('citas', $keys); $this->assertNotContains('profesionistas', $keys);
    }

    public function test_identity_content_and_history_cannot_be_edited_deleted_republished_or_replaced(): void
    {
        [$client, , , $reservation] = $this->reviewContext(); $review = $this->publishReview($reservation, 1, 'Reseña que el prestador no puede ocultar.');
        foreach ([['rating' => 5], ['comment' => 'Reemplazo'], ['service_reservation_id' => 999999], ['client_user_id' => 999999], ['provider_profile_id' => 999999],
            ['service_id' => 999999], ['created_at' => '2031-01-01 00:00:00'], ['status' => 'withdrawn'], ['status' => 'withdrawn', 'withdrawn_at_utc' => $review->created_at, 'withdrawn_by_user_id' => 999999]] as $bad) {
            try { DB::table('service_reviews')->where('id', $review->id)->update($bad); $this->fail('No editar ni retirar sin metadatos del autor.'); }
            catch (QueryException $exception) { $this->assertSame(1, $review->fresh()->rating); }
        }
        $before = $reservation->getAttributes(); app(ReviewWorkflow::class)->withdraw($client, $review->id);
        foreach ([['status' => 'published', 'withdrawn_at_utc' => null, 'withdrawn_by_user_id' => null], ['comment' => 'Cambio histórico']] as $bad) {
            try { DB::table('service_reviews')->where('id', $review->id)->update($bad); $this->fail('No reactivar ni editar una retirada.'); }
            catch (QueryException $exception) { $this->assertSame('withdrawn', $review->fresh()->status->value); }
        }
        try { DB::table('service_reviews')->delete(); $this->fail('No borrar físicamente reseñas.'); }
        catch (QueryException $exception) { $this->assertDatabaseCount('service_reviews', 1); }
        $row = $review->fresh()->getAttributes(); unset($row['id']); $row['status'] = 'published'; $row['withdrawn_at_utc'] = $row['withdrawn_by_user_id'] = null;
        try { DB::table('service_reviews')->insert($row); $this->fail('UNIQUE impide reemplazar la retirada.'); }
        catch (QueryException $exception) { $this->assertDatabaseCount('service_reviews', 1); }
        $this->assertSame($before, $reservation->fresh()->getAttributes()); $this->assertSame('ok', DB::select('PRAGMA integrity_check')[0]->integrity_check);
        $this->expectException(\Illuminate\Database\Eloquent\MassAssignmentException::class);
        new ServiceReview(['rating' => 5, 'verified' => true]);
    }
}
