<?php

namespace Tests\Feature;

use App\Enums\InquiryStatus;
use App\Enums\RoleName;
use App\Exceptions\ReservationFailure;
use App\Models\ServiceReservation;
use App\Models\User;
use App\Services\ReservationWorkflow;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\ReservationFixtures;
use Tests\TestCase;

class ReservationConcurrencyTest extends TestCase
{
    use ReservationFixtures;

    private function sharedMemory(): array
    {
        $uri = 'file:tekora-reservations-'.bin2hex(random_bytes(8)).'?mode=memory&cache=shared';
        foreach (['reservation_a', 'reservation_b'] as $name) config(['database.connections.'.$name => [
            'driver' => 'sqlite', 'database' => $uri, 'prefix' => '', 'foreign_key_constraints' => true, 'busy_timeout' => 0]]);
        DB::setDefaultConnection('reservation_a');
        $this->assertSame(0, Artisan::call('migrate', ['--database' => 'reservation_a', '--force' => true]));
        $this->assertNotSame(DB::connection('reservation_a')->getPdo(), DB::connection('reservation_b')->getPdo());
        foreach (['reservation_a', 'reservation_b'] as $name) $this->assertSame('', DB::connection($name)->select('PRAGMA database_list')[0]->file);

        return $this->reservationContext();
    }

    public function test_two_overlapping_confirmation_transactions_can_only_commit_one_occupancy(): void
    {
        [$client, $provider, $inquiry] = $this->sharedMemory();
        $other = $this->inquiry($this->account(RoleName::Client), $provider, InquiryStatus::Accepted);
        $one = $this->proposeReservation($inquiry); $two = $this->proposeReservation($other, ['time' => '09:30']);
        DB::setDefaultConnection('reservation_b'); $providerB = User::findOrFail($provider->id);
        DB::setDefaultConnection('reservation_a'); DB::connection('reservation_a')->beginTransaction();
        app(ReservationWorkflow::class)->decide($provider, $one->id, 'confirm', ['version' => 1]);
        DB::setDefaultConnection('reservation_b');
        try {
            app(ReservationWorkflow::class)->decide($providerB, $two->id, 'confirm', ['version' => 1]);
            $this->fail('La segunda petición se solapa con una escritura aún sin confirmar.');
        } catch (ReservationFailure $exception) {
            $this->assertSame(409, $exception->status); $this->assertNull($exception->getPrevious());
            $this->assertStringNotContainsString('SQL', $exception->getMessage());
        } finally { DB::connection('reservation_a')->commit(); }
        $this->actingAs($providerB)->patchJson(route('reservations.confirm', $two), ['version' => 1])->assertStatus(409)->assertDontSee('SQLSTATE');
        $this->assertSame(1, ServiceReservation::where('status', 'confirmed')->count());
        $this->assertSame('requested', $two->fresh()->status->value); $this->assertDatabaseCount('reservation_events', 3);
        $this->assertNotSame($one->service_id, $two->service_id);
    }

    public function test_direct_insert_and_update_guards_hold_across_independent_connections(): void
    {
        [, $provider, $inquiry] = $this->sharedMemory();
        $other = $this->inquiry($this->account(RoleName::Client), $provider, InquiryStatus::Accepted);
        $one = $this->proposeReservation($inquiry); $two = $this->proposeReservation($other);
        app(ReservationWorkflow::class)->decide($provider, $one->id, 'confirm', ['version' => 1]);
        DB::setDefaultConnection('reservation_b');
        try {
            DB::table('service_reservations')->where('id', $two->id)->update(['status' => 'confirmed', 'version' => 2, 'last_reason' => 'confirm', 'last_actor_user_id' => $provider->id]);
            $this->fail('UPDATE SQL directo no puede crear una doble reserva.');
        } catch (QueryException $exception) { $this->assertSame('requested', $two->fresh()->status->value); }
        $raw = $two->getAttributes(); unset($raw['id']); $raw['status'] = 'confirmed'; $raw['last_actor_user_id'] = $provider->id;
        $raw['idempotency_key'] = (string) \Illuminate\Support\Str::uuid();
        try { DB::table('service_reservations')->insert($raw); $this->fail('INSERT directo confirmado también debe rechazarse.'); }
        catch (QueryException $exception) { $this->assertDatabaseCount('service_reservations', 2); }
        $triggers = array_column(DB::select("SELECT name FROM sqlite_master WHERE type='trigger' AND name LIKE 'reservations_no_overlap_%'"), 'name');
        $this->assertEqualsCanonicalizing(['reservations_no_overlap_insert', 'reservations_no_overlap_update'], $triggers);
        $this->assertSame(1, ServiceReservation::where('status', 'confirmed')->count()); $this->assertDatabaseCount('reservation_events', 3);
    }

    public function test_adjacent_turns_and_independent_providers_are_allowed_while_pending_overlaps_do_not_occupy(): void
    {
        [, $provider, $inquiry] = $this->sharedMemory();
        $one = $this->proposeReservation($inquiry);
        $nextInquiry = $this->inquiry($this->account(RoleName::Client), $provider, InquiryStatus::Accepted);
        $next = $this->proposeReservation($nextInquiry, ['time' => '10:00']);
        $otherProvider = $this->account(RoleName::Provider); $this->service($otherProvider); $this->publishSchedule($otherProvider);
        $independent = $this->inquiry($this->account(RoleName::Client), $otherProvider, InquiryStatus::Accepted);
        $third = $this->proposeReservation($independent);
        $pendingInquiry = $this->inquiry($this->account(RoleName::Client), $provider, InquiryStatus::Accepted); $pending = $this->proposeReservation($pendingInquiry);
        $workflow = app(ReservationWorkflow::class);
        $workflow->decide($provider, $one->id, 'confirm', ['version' => 1]);
        DB::setDefaultConnection('reservation_b');
        $workflow->decide(User::findOrFail($provider->id), $next->id, 'confirm', ['version' => 1]);
        $workflow->decide(User::findOrFail($otherProvider->id), $third->id, 'confirm', ['version' => 1]);
        $this->assertSame(3, ServiceReservation::where('status', 'confirmed')->count());
        $this->assertSame('requested', $pending->fresh()->status->value);
        $this->assertSame($one->ends_at_utc, $next->starts_at_utc);
    }

    public function test_simultaneous_proposal_retries_do_not_duplicate_and_stale_decisions_never_overwrite(): void
    {
        [$client, $provider, $inquiry] = $this->sharedMemory(); $data = $this->reservationData();
        DB::setDefaultConnection('reservation_b'); $clientB = User::findOrFail($client->id); $providerB = User::findOrFail($provider->id);
        DB::setDefaultConnection('reservation_a'); DB::connection('reservation_a')->beginTransaction();
        $one = app(ReservationWorkflow::class)->propose($client, $inquiry->id, $data)['reservation'];
        DB::setDefaultConnection('reservation_b');
        try { app(ReservationWorkflow::class)->propose($clientB, $inquiry->id, $data); $this->fail('El envío simultáneo no puede intercalarse.'); }
        catch (ReservationFailure $exception) { $this->assertSame(409, $exception->status); }
        finally { DB::connection('reservation_a')->commit(); }
        $retry = app(ReservationWorkflow::class)->propose($clientB, $inquiry->id, $data);
        $this->assertFalse($retry['created']); $this->assertSame($one->id, $retry['reservation']->id);
        app(ReservationWorkflow::class)->decide($providerB, $one->id, 'confirm', ['version' => 1]);
        $this->actingAs($clientB)->patchJson(route('reservations.cancel', $one), ['version' => 1])->assertStatus(409);
        $this->assertDatabaseCount('service_reservations', 1); $this->assertDatabaseCount('reservation_events', 2);
    }

    public function test_outer_rollback_and_event_failure_restore_reservation_and_history_atomically(): void
    {
        [, $provider, $inquiry] = $this->reservationContext(); $reservation = $this->proposeReservation($inquiry); $before = $reservation->getAttributes();
        DB::beginTransaction(); app(ReservationWorkflow::class)->decide($provider, $reservation->id, 'confirm', ['version' => 1]); DB::rollBack();
        $this->assertSame($before, $reservation->fresh()->getAttributes()); $this->assertDatabaseCount('reservation_events', 1);
        DB::unprepared("CREATE TRIGGER reservation_event_failure BEFORE INSERT ON reservation_events BEGIN SELECT RAISE(ABORT, 'private test failure'); END");
        config(['app.debug' => true]);
        $this->actingAs($provider)->patchJson(route('reservations.confirm', $reservation), ['version' => 1])->assertStatus(409)->assertDontSee('SQLSTATE')->assertDontSee('private test failure');
        $this->assertSame($before, $reservation->fresh()->getAttributes()); $this->assertDatabaseCount('reservation_events', 1);
    }

    public function test_locked_and_missing_tables_return_safe_read_and_write_errors_even_with_debug_enabled(): void
    {
        [, $provider, $inquiry] = $this->sharedMemory(); $reservation = $this->proposeReservation($inquiry);
        DB::setDefaultConnection('reservation_b'); $providerB = User::findOrFail($provider->id);
        DB::setDefaultConnection('reservation_a'); DB::connection('reservation_a')->beginTransaction();
        app(ReservationWorkflow::class)->decide($provider, $reservation->id, 'confirm', ['version' => 1]);
        DB::setDefaultConnection('reservation_b'); config(['app.debug' => true]);
        try {
            $this->actingAs($providerB)->get(route('reservations.show', $reservation))->assertStatus(503)->assertDontSee('SQLSTATE')->assertDontSee('service_reservations');
            $this->patchJson(route('reservations.reject', $reservation), ['version' => 1])->assertStatus(409)->assertDontSee('SQLSTATE');
        } finally { DB::connection('reservation_a')->commit(); }
        DB::statement('ALTER TABLE service_reservations RENAME TO reservations_temporarily_missing');
        try { $this->getJson(route('reservations.show', $reservation))->assertStatus(503)->assertDontSee('SQLSTATE')->assertDontSee('service_reservations'); }
        finally { DB::statement('ALTER TABLE reservations_temporarily_missing RENAME TO service_reservations'); }
    }
}
