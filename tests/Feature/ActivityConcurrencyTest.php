<?php

namespace Tests\Feature;

use App\Exceptions\NotificationFailure;
use App\Exceptions\ReservationFailure;
use App\Exceptions\ReviewFailure;
use App\Models\ActivityNotification;
use App\Models\User;
use App\Services\NotificationInbox;
use App\Services\ReservationWorkflow;
use App\Services\ReviewWorkflow;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\ReviewFixtures;
use Tests\TestCase;

class ActivityConcurrencyTest extends TestCase
{
    use ReviewFixtures;

    private function sharedMemory(bool $completed = false): array
    {
        $uri = 'file:tekora-activity-'.bin2hex(random_bytes(8)).'?mode=memory&cache=shared';
        foreach (['activity_a', 'activity_b'] as $name) config(['database.connections.'.$name => [
            'driver' => 'sqlite', 'database' => $uri, 'prefix' => '', 'foreign_key_constraints' => true, 'busy_timeout' => 0]]);
        DB::setDefaultConnection('activity_a');
        $this->assertSame(0, Artisan::call('migrate', ['--database' => 'activity_a', '--force' => true]));
        $this->assertNotSame(DB::connection('activity_a')->getPdo(), DB::connection('activity_b')->getPdo());
        foreach (['activity_a', 'activity_b'] as $name) $this->assertSame('', DB::connection($name)->select('PRAGMA database_list')[0]->file);

        return $completed ? $this->reviewContext() : $this->reservationContext();
    }

    public function test_overlapping_proposals_and_retries_only_commit_one_notification(): void
    {
        [$client, $provider, $inquiry] = $this->sharedMemory(); $data = $this->reservationData();
        DB::setDefaultConnection('activity_b'); $clientB = User::findOrFail($client->id); DB::setDefaultConnection('activity_a');
        DB::connection('activity_a')->beginTransaction();
        $first = app(ReservationWorkflow::class)->propose($client, $inquiry->id, $data)['reservation']; DB::setDefaultConnection('activity_b');
        try { app(ReservationWorkflow::class)->propose($clientB, $inquiry->id, $data); $this->fail('El segundo envío no debe atravesar la primera transacción.'); }
        catch (ReservationFailure $exception) { $this->assertSame(409, $exception->status); }
        finally { DB::connection('activity_a')->commit(); }
        $retry = app(ReservationWorkflow::class)->propose($clientB, $inquiry->id, $data);
        $this->assertFalse($retry['created']); $this->assertSame($first->id, $retry['reservation']->id);
        $this->assertDatabaseCount('activity_notifications', 1); $this->assertSame($provider->id, ActivityNotification::sole()->user_id);
    }

    public function test_overlapping_decisions_only_notify_the_winning_version(): void
    {
        [$client, $provider, $inquiry] = $this->sharedMemory(); $reservation = $this->proposeReservation($inquiry);
        DB::setDefaultConnection('activity_b'); $providerB = User::findOrFail($provider->id); DB::setDefaultConnection('activity_a');
        DB::connection('activity_a')->beginTransaction(); app(ReservationWorkflow::class)->decide($provider, $reservation->id, 'confirm', ['version' => 1]);
        DB::setDefaultConnection('activity_b');
        try { app(ReservationWorkflow::class)->decide($providerB, $reservation->id, 'reject', ['version' => 1]); $this->fail('No decidir simultáneamente dos estados.'); }
        catch (ReservationFailure $exception) { $this->assertSame(409, $exception->status); }
        finally { DB::connection('activity_a')->commit(); }
        $this->actingAs($providerB)->patchJson(route('reservations.confirm', $reservation), ['version' => 1])->assertStatus(409);
        $this->assertDatabaseCount('activity_notifications', 2);
        $this->assertSame(['reservation_requested', 'reservation_confirmed'], ActivityNotification::orderBy('id')->get()->pluck('event')->map->value->all());
    }

    public function test_overlapping_review_publications_and_withdrawals_do_not_duplicate_notifications(): void
    {
        [$client, $provider, , $reservation] = $this->sharedMemory(true);
        DB::setDefaultConnection('activity_b'); $clientB = User::findOrFail($client->id); DB::setDefaultConnection('activity_a');
        DB::connection('activity_a')->beginTransaction(); $review = $this->publishReview($reservation, 4, 'Mismo comentario.'); DB::setDefaultConnection('activity_b');
        try { app(ReviewWorkflow::class)->publish($clientB, $reservation->id, ['rating' => 4, 'comment' => 'Mismo comentario.']); $this->fail('Publicación concurrente.'); }
        catch (ReviewFailure $exception) { $this->assertSame(409, $exception->status); }
        finally { DB::connection('activity_a')->commit(); }
        $this->assertFalse(app(ReviewWorkflow::class)->publish($clientB, $reservation->id, ['rating' => 4, 'comment' => 'Mismo comentario.'])['created']);
        DB::setDefaultConnection('activity_a'); DB::connection('activity_a')->beginTransaction(); app(ReviewWorkflow::class)->withdraw($client, $review->id); DB::setDefaultConnection('activity_b');
        try { app(ReviewWorkflow::class)->withdraw($clientB, $review->id); $this->fail('Retiro concurrente.'); }
        catch (ReviewFailure $exception) { $this->assertSame(409, $exception->status); }
        finally { DB::connection('activity_a')->commit(); }
        app(ReviewWorkflow::class)->withdraw($clientB, $review->id);
        $this->assertSame(2, ActivityNotification::where('service_review_id', $review->id)->count()); $this->assertDatabaseCount('activity_notifications', 5);
    }

    public function test_overlapping_read_updates_keep_the_first_read_time_and_preserve_the_source(): void
    {
        [$client, $provider, , $reservation] = $this->sharedMemory(true);
        $notice = ActivityNotification::where('user_id', $client->id)->firstOrFail(); $before = $this->reservationRows(['service_reservations', 'reservation_events']);
        DB::setDefaultConnection('activity_b'); $clientB = User::findOrFail($client->id); DB::setDefaultConnection('activity_a');
        DB::connection('activity_a')->beginTransaction(); $read = app(NotificationInbox::class)->readActivity($client, (string) $notice->id); DB::setDefaultConnection('activity_b');
        try { app(NotificationInbox::class)->readActivity($clientB, (string) $notice->id); $this->fail('Lectura concurrente.'); }
        catch (NotificationFailure $exception) { $this->assertSame(409, $exception->status); $this->assertNull($exception->getPrevious()); }
        finally { DB::connection('activity_a')->commit(); }
        $this->travel(10)->minutes(); $again = app(NotificationInbox::class)->readActivity($clientB, (string) $notice->id);
        $this->assertSame($read->read_at, $again->read_at); $this->assertSame($before, $this->reservationRows(['service_reservations', 'reservation_events']));
        $this->assertSame([], DB::select('PRAGMA foreign_key_check')); $this->assertSame('ok', DB::select('PRAGMA integrity_check')[0]->integrity_check);
    }

    public function test_listing_and_counter_share_one_snapshot_while_another_connection_attempts_reading(): void
    {
        [$client, $provider] = $this->sharedMemory(true); $notice = ActivityNotification::where('user_id', $client->id)->firstOrFail();
        DB::setDefaultConnection('activity_b'); $clientB = User::findOrFail($client->id); DB::setDefaultConnection('activity_a');
        $attempted = false; $blocked = false;
        DB::listen(function ($event) use (&$attempted, &$blocked, $clientB, $notice) {
            if ($attempted || $event->connectionName !== 'activity_a' || ! str_contains($event->sql, 'union all')) return;
            $attempted = true; DB::setDefaultConnection('activity_b');
            try { app(NotificationInbox::class)->readActivity($clientB, (string) $notice->id); }
            catch (NotificationFailure $exception) { $blocked = $exception->status === 409; }
            finally { DB::setDefaultConnection('activity_a'); }
        });
        $this->actingAs($client)->get(route('notifications.index'))->assertOk()->assertSee('3 sin leer')->assertSee('No leída');
        $this->assertTrue($attempted); $this->assertTrue($blocked);
        DB::setDefaultConnection('activity_b'); app(NotificationInbox::class)->readActivity($clientB, (string) $notice->id);
        $this->assertSame(2, app(NotificationInbox::class)->unreadCount($clientB));
    }

    public function test_locked_or_missing_storage_is_safe_with_debug_and_menu_does_not_fake_zero(): void
    {
        [$client, $provider, , $reservation] = $this->sharedMemory(true); $notice = ActivityNotification::where('user_id', $client->id)->firstOrFail();
        DB::setDefaultConnection('activity_b'); $clientB = User::findOrFail($client->id); DB::setDefaultConnection('activity_a');
        DB::connection('activity_a')->beginTransaction(); app(NotificationInbox::class)->readActivity($client, (string) $notice->id); DB::setDefaultConnection('activity_b'); config(['app.debug' => true]);
        try {
            $this->actingAs($clientB)->get(route('notifications.index'))->assertStatus(503)->assertDontSee('SQLSTATE')->assertDontSee('activity_notifications');
            $this->postJson(route('notifications.activity.read', $notice))->assertStatus(409)->assertDontSee('SQLSTATE');
            $this->assertNull(app(NotificationInbox::class)->unreadCount($clientB));
        } finally { DB::connection('activity_a')->commit(); }
        DB::statement('ALTER TABLE activity_notifications RENAME TO activity_temporarily_missing');
        try {
            $this->get(route('account.index'))->assertOk()->assertSee('Contador no disponible')->assertDontSee('SQLSTATE');
            $this->get(route('notifications.index'))->assertStatus(503)->assertDontSee('SQLSTATE');
            $this->postJson(route('notifications.activity.read', $notice))->assertStatus(503)->assertDontSee('SQLSTATE');
        } finally { DB::statement('ALTER TABLE activity_temporarily_missing RENAME TO activity_notifications'); }
    }
}
