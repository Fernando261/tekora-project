<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Exceptions\AvailabilityFailure;
use App\Models\ProviderAvailabilitySetting;
use App\Models\User;
use App\Services\ProviderAvailability;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\AvailabilityFixtures;
use Tests\TestCase;

class AvailabilityConcurrencyTest extends TestCase
{
    use AvailabilityFixtures;

    private function sharedMemory(): User
    {
        $uri = 'file:tekora-availability-'.bin2hex(random_bytes(8)).'?mode=memory&cache=shared';
        foreach (['availability_a', 'availability_b'] as $name) config(['database.connections.'.$name => [
            'driver' => 'sqlite', 'database' => $uri, 'prefix' => '', 'foreign_key_constraints' => true, 'busy_timeout' => 0]]);
        DB::setDefaultConnection('availability_a');
        $this->assertSame(0, Artisan::call('migrate', ['--database' => 'availability_a', '--force' => true]));
        $this->assertNotSame(DB::connection('availability_a')->getPdo(), DB::connection('availability_b')->getPdo());
        foreach (['availability_a', 'availability_b'] as $name) $this->assertSame('', DB::connection($name)->select('PRAGMA database_list')[0]->file);
        $owner = $this->account(RoleName::Provider); $this->service($owner);

        return $owner;
    }

    public function test_overlapping_initial_saves_create_one_configuration_and_the_stale_request_cannot_replace_it(): void
    {
        $owner = $this->sharedMemory(); $availability = app(ProviderAvailability::class);
        DB::connection('availability_a')->beginTransaction();
        $availability->save($owner, $this->availabilityData());
        DB::setDefaultConnection('availability_b'); $ownerB = User::findOrFail($owner->id);
        try {
            $availability->save($ownerB, $this->availabilityData(['timezone' => 'UTC']));
            $this->fail('La segunda escritura no debe intercalarse con la primera.');
        } catch (AvailabilityFailure $exception) {
            $this->assertSame(409, $exception->status);
        } finally {
            DB::connection('availability_a')->commit();
        }
        try {
            $availability->save($ownerB, $this->availabilityData(['timezone' => 'UTC']));
            $this->fail('La revisión cero ya fue consumida.');
        } catch (AvailabilityFailure $exception) {
            $this->assertSame(409, $exception->status);
        }
        $this->assertDatabaseCount('provider_availability_settings', 1); $this->assertDatabaseCount('provider_weekly_intervals', 3);
        $this->assertSame('America/Mexico_City', ProviderAvailabilitySetting::sole()->timezone);
        $this->assertSame(1, ProviderAvailabilitySetting::sole()->revision);
    }

    public function test_overlapping_edits_keep_the_winner_and_require_a_fresh_revision_before_replacement(): void
    {
        $owner = $this->sharedMemory(); $availability = app(ProviderAvailability::class);
        $availability->save($owner, $this->availabilityData());
        DB::setDefaultConnection('availability_b'); $ownerB = User::findOrFail($owner->id);
        DB::setDefaultConnection('availability_a'); DB::connection('availability_a')->beginTransaction();
        $winner = $this->availabilityData(['revision' => 1, 'timezone' => 'Europe/Madrid', 'intervals' => [['day_of_week' => 1, 'start' => '08:00', 'end' => '16:00']]]);
        $availability->save($owner, $winner);
        DB::setDefaultConnection('availability_b');
        try {
            $availability->save($ownerB, $this->availabilityData(['revision' => 1]));
            $this->fail('La edición simultánea debe recibir conflicto.');
        } catch (AvailabilityFailure $exception) {
            $this->assertSame(409, $exception->status);
        } finally {
            DB::connection('availability_a')->commit();
        }
        $this->actingAs($ownerB, 'web')->patchJson(route('availability.update'), $this->availabilityData(['revision' => 1]))->assertStatus(409);
        $this->assertSame(2, ProviderAvailabilitySetting::sole()->revision);
        $this->assertSame('Europe/Madrid', ProviderAvailabilitySetting::sole()->timezone);
        $this->assertDatabaseCount('provider_weekly_intervals', 1);
        $this->assertSame(480, DB::table('provider_weekly_intervals')->value('start_minute'));
        $this->patch(route('availability.update'), $this->availabilityData(['revision' => 2]))->assertStatus(303);
        $this->assertSame(3, ProviderAvailabilitySetting::sole()->revision);
    }

    public function test_database_overlap_guards_protect_two_writers_even_without_the_service(): void
    {
        $owner = $this->sharedMemory(); $profileId = $owner->providerProfile->id;
        $row = ['provider_profile_id' => $profileId, 'day_of_week' => 1, 'start_minute' => 540, 'end_minute' => 720];
        DB::table('provider_weekly_intervals')->insert($row);
        DB::setDefaultConnection('availability_b');
        foreach ([[600, 780], [540, 720], [500, 800]] as [$start, $end]) {
            try {
                DB::table('provider_weekly_intervals')->insert(array_replace($row, ['start_minute' => $start, 'end_minute' => $end]));
                $this->fail('La base debe impedir la superposición sin depender del controlador.');
            } catch (QueryException $exception) {
                $this->assertDatabaseCount('provider_weekly_intervals', 1);
            }
        }
        $secondId = DB::table('provider_weekly_intervals')->insertGetId(array_replace($row, ['start_minute' => 720, 'end_minute' => 800]));
        try {
            DB::table('provider_weekly_intervals')->where('id', $secondId)->update(['start_minute' => 700]);
            $this->fail('El guard debe proteger también UPDATE.');
        } catch (QueryException $exception) {
            $this->assertSame(720, DB::table('provider_weekly_intervals')->where('id', $secondId)->value('start_minute'));
        }
        $this->assertDatabaseCount('provider_weekly_intervals', 2);
    }

    public function test_outer_rollback_preserves_all_three_configuration_tables_and_revision(): void
    {
        $owner = $this->account(RoleName::Provider); $this->service($owner);
        $availability = app(ProviderAvailability::class); $availability->save($owner, $this->availabilityData());
        $before = ProviderAvailabilitySetting::sole()->getAttributes();
        DB::beginTransaction(); $availability->save($owner, $this->availabilityData(['revision' => 1, 'timezone' => 'UTC', 'intervals' => [], 'blocked_dates' => []])); DB::rollBack();
        $this->assertSame($before, ProviderAvailabilitySetting::sole()->getAttributes());
        $this->assertDatabaseCount('provider_weekly_intervals', 3); $this->assertDatabaseCount('provider_blocked_dates', 2);
    }

    public function test_public_read_cannot_mix_timezone_and_intervals_from_different_revisions(): void
    {
        $owner = $this->sharedMemory(); $availability = app(ProviderAvailability::class);
        $availability->save($owner, $this->availabilityData());
        $profile = $owner->providerProfile;
        DB::setDefaultConnection('availability_b'); $ownerB = User::findOrFail($owner->id); DB::setDefaultConnection('availability_a');
        $edited = $this->availabilityData(['revision' => 1, 'timezone' => 'UTC', 'intervals' => [['day_of_week' => 1, 'start' => '18:00', 'end' => '20:00']]]);
        $attempted = false; $blocked = false;
        DB::listen(function ($event) use (&$attempted, &$blocked, $availability, $ownerB, $edited) {
            if ($attempted || $event->connectionName !== 'availability_a' || ! str_starts_with($event->sql, 'select * from "provider_availability_settings"')) return;
            $attempted = true; DB::setDefaultConnection('availability_b');
            try {
                $availability->save($ownerB, $edited);
            } catch (AvailabilityFailure $exception) {
                $blocked = $exception->status === 409;
            } finally {
                DB::setDefaultConnection('availability_a');
            }
        });
        $schedule = $availability->publicFor($profile);
        $this->assertTrue($attempted); $this->assertTrue($blocked);
        $this->assertSame('America/Mexico_City', $schedule['timezone']);
        $this->assertSame(540, $schedule['days']->get(1)->first()->start_minute);
        DB::setDefaultConnection('availability_b'); $availability->save($ownerB, $edited);
        $schedule = $availability->publicFor($ownerB->providerProfile);
        $this->assertSame('UTC', $schedule['timezone']);
        $this->assertSame(1080, $schedule['days']->get(1)->first()->start_minute);
    }

    public function test_readers_get_safe_temporary_errors_while_a_writer_holds_the_configuration(): void
    {
        $owner = $this->sharedMemory(); $availability = app(ProviderAvailability::class);
        $availability->save($owner, $this->availabilityData());
        DB::setDefaultConnection('availability_b'); $ownerB = User::findOrFail($owner->id); DB::setDefaultConnection('availability_a');
        DB::connection('availability_a')->beginTransaction();
        $availability->save($owner, $this->availabilityData(['revision' => 1, 'timezone' => 'UTC']));
        DB::setDefaultConnection('availability_b'); config(['app.debug' => true]);
        try {
            $this->assertSame(['unavailable' => true], $availability->publicFor($ownerB->providerProfile));
            $service = $ownerB->providerProfile->services()->firstOrFail();
            $this->get(route('catalog.show', $service))->assertOk()->assertSee('Horario temporalmente no disponible')->assertDontSee('SQLSTATE')->assertDontSee('provider_availability_settings');
            $this->actingAs($ownerB, 'web')->get(route('availability.edit'))->assertStatus(503)->assertSee('No se pudo consultar')->assertDontSee('SQLSTATE')->assertDontSee('provider_availability_settings');
        } finally {
            DB::connection('availability_a')->commit();
        }
        $this->get(route('availability.edit'))->assertOk()->assertSee('value="UTC" selected', false);
    }
}
