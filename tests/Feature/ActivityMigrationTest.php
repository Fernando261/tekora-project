<?php

namespace Tests\Feature;

use App\Models\ActivityNotification;
use App\Models\Cita;
use App\Models\Profesionista;
use App\Services\NotificationInbox;
use App\Services\ReviewWorkflow;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\Support\ReviewFixtures;
use Tests\TestCase;

class ActivityMigrationTest extends TestCase
{
    use ReviewFixtures;

    private function snapshot(array $tables): array
    {
        $snapshot = [];
        foreach ($tables as $table) $snapshot[$table] = ['columns' => Schema::getColumnListing($table), 'rows' => DB::table($table)->get()->map(fn ($r) => (array) $r)->sortBy(fn ($r) => json_encode($r))->values()->all()];

        return $snapshot;
    }

    public function test_upgrade_35_to_36_preserves_all_previous_data_and_does_not_backfill_activity(): void
    {
        config(['database.connections.activity_upgrade' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
        DB::setDefaultConnection('activity_upgrade');
        $files = array_values(array_filter(glob(database_path('migrations/*.php')), fn ($file) => basename($file) < '2026_11_03_000002'));
        $this->assertCount(35, $files); $this->assertSame(0, Artisan::call('migrate', ['--database' => 'activity_upgrade', '--path' => $files, '--realpath' => true, '--force' => true]));
        $this->assertDatabaseCount('migrations', 35);
        [$client, $provider, $inquiry, $reservation] = $this->reviewContext(); $review = $this->publishReview($reservation); $this->message($provider, $inquiry);
        $legacy = Profesionista::create(['nombre' => 'Anterior', 'email' => $client->email, 'cedula' => 'ACTIVITY-UPGRADE', 'password' => Hash::make('separate-password'), 'google_access_token' => 'fake-preserved-access', 'google_refresh_token' => 'fake-preserved-refresh']);
        Cita::create(['user_id' => $client->id, 'profesionista_id' => $legacy->id, 'start_datetime' => '2030-01-07 10:00:00', 'end_datetime' => '2030-01-07 10:50:00',
            'starts_at' => '2030-01-07 10:00:00', 'ends_at' => '2030-01-07 10:50:00', 'estado' => 'pendiente', 'status' => 'pending', 'motivo' => 'Conservar cita']);
        DB::table('profesionista_disponibilidades')->insert(['profesionista_id' => $legacy->id, 'day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '18:00', 'is_active' => 1]);
        DB::table('profesionista_bloqueos')->insert(['profesionista_id' => $legacy->id, 'start_datetime' => '2030-01-08 10:00:00', 'end_datetime' => '2030-01-08 11:00:00']);
        DB::table('sessions')->insert(['id' => 'activity-session', 'user_id' => $client->id, 'payload' => 'opaque-fixture', 'last_activity' => 1]);
        $tables = array_values(array_filter(array_column(Schema::getTables(), 'name'), fn ($t) => $t !== 'migrations')); $before = $this->snapshot($tables);
        $this->assertSame(0, Artisan::call('migrate', ['--database' => 'activity_upgrade', '--path' => [database_path('migrations/2026_11_03_000002_create_activity_notifications.php')], '--realpath' => true, '--force' => true]));
        $this->assertDatabaseCount('migrations', 36); $this->assertDatabaseCount('activity_notifications', 0); $this->assertSame($before, $this->snapshot($tables));
        // El retiro es un hecho nuevo, aunque la publicación original preceda a esta migración.
        app(ReviewWorkflow::class)->withdraw($client, $review->id); $notice = ActivityNotification::sole();
        $this->assertSame('review_withdrawn', $notice->event->value); $this->assertSame($review->id, $notice->review->id);
        $this->assertSame($provider->id, $notice->user->id); $this->assertSame($notice->id, $provider->activityNotifications()->sole()->id);
        $afterAction = $this->snapshot($tables); app(NotificationInbox::class)->readActivity($provider, (string) $notice->id);
        $this->assertSame($afterAction, $this->snapshot($tables));
        $this->assertSame([], DB::select('PRAGMA foreign_key_check')); $this->assertSame('ok', DB::select('PRAGMA integrity_check')[0]->integrity_check);
        // Exclusivamente memoria: down() elimina solo la tabla y los hooks de actividad nuevos.
        $this->assertSame(0, Artisan::call('migrate:rollback', ['--database' => 'activity_upgrade', '--step' => 1, '--force' => true]));
        $this->assertDatabaseCount('migrations', 35); $this->assertFalse(Schema::hasTable('activity_notifications')); $this->assertSame($afterAction, $this->snapshot($tables));
        $this->assertSame([], DB::select("SELECT name FROM sqlite_master WHERE type='trigger' AND name LIKE 'activity_%'"));
        $this->assertSame(0, Artisan::call('migrate', ['--database' => 'activity_upgrade', '--path' => [database_path('migrations/2026_11_03_000002_create_activity_notifications.php')], '--realpath' => true, '--force' => true]));
        $this->assertDatabaseCount('migrations', 36); $this->assertDatabaseCount('activity_notifications', 0); $this->assertSame($afterAction, $this->snapshot($tables));
    }

    public function test_database_enforces_source_type_foreign_keys_recipient_event_version_and_uniqueness(): void
    {
        [$client, $provider, , $reservation] = $this->reviewContext(); $review = $this->publishReview($reservation); app(ReviewWorkflow::class)->withdraw($client, $review->id);
        $reservationRow = ActivityNotification::where('service_reservation_id', $reservation->id)->where('reservation_version', 1)->sole()->getAttributes(); unset($reservationRow['id']);
        $reviewRow = ActivityNotification::where('service_review_id', $review->id)->where('event', 'review_published')->sole()->getAttributes(); unset($reviewRow['id']);
        $cases = [[$reservationRow, []], [$reviewRow, []], [$reservationRow, ['user_id' => $client->id]], [$reviewRow, ['user_id' => $client->id]],
            [$reservationRow, ['user_id' => 999999]], [$reservationRow, ['reservation_version' => 999]], [$reservationRow, ['reservation_version' => 1.5]],
            [$reservationRow, ['event' => 'reservation_confirmed']], [$reservationRow, ['event' => 'review_published']], [$reviewRow, ['event' => 'reservation_requested']],
            [$reservationRow, ['service_reservation_id' => null]], [$reservationRow, ['service_review_id' => $review->id]], [$reviewRow, ['reservation_version' => 1]],
            [$reviewRow, ['service_review_id' => 999999]], [$reviewRow, ['event' => 'admin_granted']], [$reviewRow, ['created_at' => '2030-02-30 00:00:00']],
            [$reviewRow, ['read_at' => now('UTC')->format('Y-m-d H:i:s')]]];
        foreach ($cases as [$row, $bad]) {
            try { DB::table('activity_notifications')->insert(array_replace($row, $bad)); $this->fail('La base debe rechazar avisos falsos o duplicados.'); }
            catch (QueryException $exception) { $this->assertDatabaseCount('activity_notifications', 5); }
        }
        $keys = array_column(DB::select('PRAGMA foreign_key_list(activity_notifications)'), 'table');
        $this->assertContains('reservation_events', $keys); $this->assertContains('service_reservations', $keys); $this->assertContains('service_reviews', $keys); $this->assertContains('users', $keys);
        $this->assertNotContains('citas', $keys); $this->assertNotContains('profesionistas', $keys);
        $this->assertSame([], DB::select('PRAGMA foreign_key_check')); $this->assertSame('ok', DB::select('PRAGMA integrity_check')[0]->integrity_check);
    }

    public function test_identity_and_history_are_immutable_and_reading_only_moves_null_to_one_valid_date(): void
    {
        [$client, $provider, , $reservation] = $this->reviewContext(); $notice = ActivityNotification::where('user_id', $provider->id)->sole();
        $read = now('UTC')->format('Y-m-d H:i:s');
        foreach ([['user_id' => $client->id], ['event' => 'reservation_confirmed'], ['reservation_version' => 2], ['service_reservation_id' => 999],
            ['service_review_id' => 999], ['created_at' => $read], ['read_at' => 'invalid'], ['read_at' => '1900-01-01 00:00:00']] as $bad) {
            try { DB::table('activity_notifications')->where('id', $notice->id)->update(array_replace(['read_at' => $read], $bad)); $this->fail('No cambiar identidad, evento ni fechas de origen.'); }
            catch (QueryException $exception) { $this->assertNull($notice->fresh()->read_at); }
        }
        app(NotificationInbox::class)->readActivity($provider, (string) $notice->id);
        foreach ([null, '2031-01-01 00:00:00'] as $date) {
            try { DB::table('activity_notifications')->where('id', $notice->id)->update(['read_at' => $date]); $this->fail('La lectura no puede volver atrás ni sustituir su fecha.'); }
            catch (QueryException $exception) { $this->assertSame($read, $notice->fresh()->read_at); }
        }
        try { DB::table('activity_notifications')->delete(); $this->fail('No borrar historial.'); }
        catch (QueryException $exception) { $this->assertDatabaseCount('activity_notifications', 3); }
        $this->expectException(\Illuminate\Database\Eloquent\MassAssignmentException::class);
        new ActivityNotification(['user_id' => 999, 'event' => 'reservation_completed']);
    }

    public function test_valid_direct_business_transition_emits_the_same_notice_without_http_or_observers(): void
    {
        [$client, $provider, $inquiry] = $this->reservationContext(); $reservation = $this->proposeReservation($inquiry);
        DB::table('service_reservations')->where('id', $reservation->id)->update(['status' => 'confirmed', 'version' => 2, 'last_actor_user_id' => $provider->id,
            'last_reason' => 'confirm', 'updated_at' => now('UTC')->format('Y-m-d H:i:s')]);
        $notice = ActivityNotification::where('service_reservation_id', $reservation->id)->where('reservation_version', 2)->sole();
        $this->assertSame($client->id, $notice->user_id); $this->assertSame('reservation_confirmed', $notice->event->value);
        $this->assertDatabaseCount('activity_notifications', 2); $this->assertDatabaseCount('reservation_events', 2);
    }
}
