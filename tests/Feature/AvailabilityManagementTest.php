<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\ProviderAvailabilitySetting;
use App\Models\ProviderWeeklyInterval;
use App\Services\ProviderAvailability;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Support\AvailabilityFixtures;
use Tests\TestCase;

class AvailabilityManagementTest extends TestCase
{
    use AvailabilityFixtures;

    public function test_owner_publishes_edits_multiple_intervals_and_closes_days_without_affecting_the_profile(): void
    {
        $owner = $this->account(RoleName::Client, RoleName::Provider); $service = $this->service($owner);
        $profileBefore = $service->providerProfile->getAttributes();
        $this->actingAs($owner, 'web')->get(route('availability.edit'))->assertOk()->assertSee('Horario no publicado')->assertSee('America/Mexico_City');
        $this->assertDatabaseCount('provider_availability_settings', 0);
        $this->patch(route('availability.update'), $this->availabilityData())->assertStatus(303)->assertSessionHas('success');
        $setting = ProviderAvailabilitySetting::sole();
        $this->assertSame(1, $setting->revision); $this->assertSame($owner->providerProfile->id, $setting->provider_profile_id);
        $this->assertSame($service->provider_profile_id, $setting->providerProfile->id);
        $this->assertSame([540, 780, 600], $owner->providerProfile->weeklyIntervals()->orderBy('day_of_week')->orderBy('start_minute')->pluck('start_minute')->all());
        $this->assertDatabaseCount('provider_blocked_dates', 2);
        $this->get(route('availability.edit'))->assertOk()->assertSee('value="09:00"', false)->assertSee('2030-12-25');
        $this->patch(route('availability.update'), $this->availabilityData(['revision' => 1, 'timezone' => 'Europe/Madrid',
            'intervals' => [['day_of_week' => 7, 'start' => '11:00', 'end' => '14:00']], 'blocked_dates' => []]))->assertStatus(303);
        $this->assertSame(2, $setting->fresh()->revision); $this->assertSame('Europe/Madrid', $setting->fresh()->timezone);
        $this->assertDatabaseCount('provider_weekly_intervals', 1); $this->assertDatabaseCount('provider_blocked_dates', 0);
        $this->assertSame($profileBefore, $service->providerProfile->fresh()->getAttributes());
        $this->get(route('public-providers.show', $service->provider_profile_id))->assertOk()->assertSee('Sin atención')->assertSee('11:00–14:00');
    }

    public function test_client_admin_and_third_party_payloads_cannot_edit_another_profile(): void
    {
        $owner = $this->account(RoleName::Provider); $service = $this->service($owner);
        app(ProviderAvailability::class)->save($owner, $this->availabilityData());
        $before = ProviderAvailabilitySetting::sole()->getAttributes();
        foreach ([[], [RoleName::Client], [RoleName::Admin]] as $roles) {
            $this->actingAs($this->account(...$roles), 'web')->get(route('availability.edit'))->assertForbidden();
            $this->patchJson(route('availability.update'), $this->availabilityData())->assertForbidden();
        }
        $third = $this->account(RoleName::Provider, RoleName::Admin); $this->service($third);
        $this->actingAs($third, 'web')->patchJson(route('availability.update'), $this->availabilityData(['provider_profile_id' => $service->provider_profile_id, 'user_id' => $owner->id]))
            ->assertUnprocessable()->assertJsonValidationErrors(['provider_profile_id', 'user_id']);
        $this->get('/cuenta/disponibilidad/'.$service->provider_profile_id)->assertNotFound();
        $this->patch(route('availability.update'), $this->availabilityData(['timezone' => 'UTC']))->assertStatus(303);
        $this->assertSame($before, ProviderAvailabilitySetting::findOrFail($before['id'])->getAttributes());
        $this->assertDatabaseCount('provider_availability_settings', 2);
    }

    public function test_provider_without_profile_is_guided_to_complete_it_and_role_revocation_blocks_prepared_edits(): void
    {
        $owner = $this->account(RoleName::Provider);
        $this->actingAs($owner, 'web')->get(route('availability.edit'))->assertRedirect(route('account.provider'));
        $this->patchJson(route('availability.update'), $this->availabilityData())->assertNotFound();
        $this->service($owner); app(ProviderAvailability::class)->save($owner, $this->availabilityData());
        $owner->roles()->detach();
        $this->patchJson(route('availability.update'), $this->availabilityData(['revision' => 1]))->assertForbidden();
        $this->get(route('availability.edit'))->assertForbidden();
        $this->assertSame(1, ProviderAvailabilitySetting::sole()->revision);
    }

    public function test_invalid_hours_days_timezones_and_nested_identity_fields_are_rejected(): void
    {
        $owner = $this->account(RoleName::Provider); $this->service($owner); $this->actingAs($owner, 'web');
        $invalid = [
            ['intervals' => [['day_of_week' => 0, 'start' => '09:00', 'end' => '10:00']]],
            ['intervals' => [['day_of_week' => 8, 'start' => '09:00', 'end' => '10:00']]],
            ['intervals' => [['day_of_week' => 1, 'start' => '24:00', 'end' => '25:00']]],
            ['intervals' => [['day_of_week' => 1, 'start' => '9:00', 'end' => '10:00']]],
            ['intervals' => [['day_of_week' => 1, 'start' => '09:00:00', 'end' => '10:00']]],
            ['intervals' => [['day_of_week' => 1, 'start' => '09:00', 'end' => '09:00']]],
            ['intervals' => [['day_of_week' => 1, 'start' => '23:00', 'end' => '01:00']]],
            ['intervals' => [['day_of_week' => 1, 'start' => '09:00', 'end' => '']]],
            ['intervals' => [['day_of_week' => ['bad'], 'start' => [], 'end' => '10:00']]],
            ['intervals' => [['day_of_week' => 1, 'start' => '09:00', 'end' => '10:00', 'provider_profile_id' => 999]]],
            ['timezone' => '+02:00'], ['timezone' => 'CST'], ['timezone' => 'Invalid/Timezone'],
            ['roles' => ['admin']], ['revision' => -1], ['intervals' => array_fill(0, 43, ['day_of_week' => 1])],
        ];
        // Aislar las reglas de validación; la frecuencia tiene su propia prueba.
        \Illuminate\Support\Facades\RateLimiter::for('provider-availability', fn () => \Illuminate\Cache\RateLimiting\Limit::none());
        foreach ($invalid as $index => $change) {
            $this->patchJson(route('availability.update'), $this->availabilityData($change))->assertUnprocessable();
        }
        $this->assertDatabaseCount('provider_availability_settings', 0); $this->assertDatabaseCount('provider_weekly_intervals', 0);
        $this->assertFalse($owner->hasRole(RoleName::Admin));
    }

    public function test_overlaps_duplicates_and_containment_fail_but_adjacent_turns_and_other_days_are_valid(): void
    {
        $owner = $this->account(RoleName::Provider); $this->service($owner); $this->actingAs($owner, 'web');
        foreach ([['10:00', '11:00'], ['08:00', '14:00'], ['09:00', '12:00'], ['11:00', '13:00']] as [$start, $end]) {
            $this->patchJson(route('availability.update'), $this->availabilityData(['intervals' => [
                ['day_of_week' => 1, 'start' => '09:00', 'end' => '12:00'], ['day_of_week' => 1, 'start' => $start, 'end' => $end]]]))
                ->assertUnprocessable();
        }
        $this->patch(route('availability.update'), $this->availabilityData(['intervals' => [
            ['day_of_week' => 1, 'start' => '09:00', 'end' => '12:00'], ['day_of_week' => 1, 'start' => '12:00', 'end' => '17:00'],
            ['day_of_week' => 2, 'start' => '09:00', 'end' => '12:00']]]))->assertStatus(303);
        $this->assertDatabaseCount('provider_weekly_intervals', 3);
        $this->assertSame('12:00', ProviderWeeklyInterval::civilTime(720));
    }

    public function test_blank_rows_close_days_and_a_maximum_sized_configuration_can_be_edited(): void
    {
        $owner = $this->account(RoleName::Provider); $this->service($owner);
        $rows = [];
        for ($i = 0; $i < 42; $i++) $rows[] = ['day_of_week' => 1, 'start' => sprintf('%02d:%02d', intdiv($i * 2, 60), $i * 2 % 60),
            'end' => sprintf('%02d:%02d', intdiv($i * 2 + 1, 60), ($i * 2 + 1) % 60)];
        $this->actingAs($owner, 'web')->patch(route('availability.update'), $this->availabilityData(['intervals' => $rows]))->assertStatus(303);
        $html = $this->get(route('availability.edit'))->assertOk()->getContent();
        // Incluye el campo plantilla sin name; no debe añadir filas que excedan el máximo enviado al servidor.
        $this->assertSame(42, preg_match_all('/name="intervals\[[0-9]+\]\[day_of_week\]"/', $html));
        $this->patch(route('availability.update'), $this->availabilityData(['revision' => 1, 'intervals' => [
            ['day_of_week' => 1, 'start' => '', 'end' => ''], ['day_of_week' => 7, 'start' => null, 'end' => null]], 'blocked_dates' => []]))->assertStatus(303);
        $this->assertDatabaseCount('provider_weekly_intervals', 0);
        $this->assertDatabaseCount('provider_availability_settings', 1);
    }

    public function test_validation_keeps_safe_input_and_does_not_render_malformed_arrays_as_html(): void
    {
        $owner = $this->account(RoleName::Provider); $this->service($owner);
        $this->actingAs($owner, 'web')->from(route('availability.edit'))->patch(route('availability.update'), $this->availabilityData([
            'intervals' => [['day_of_week' => ['malicious'], 'start' => ['bad'], 'end' => '</input><script>BAD()</script>']],
            'blocked_dates' => [['bad']], 'timezone' => ['bad'], 'revision' => ['bad']]))->assertSessionHasErrors();
        $this->get(route('availability.edit'))->assertOk()->assertDontSee('<script>BAD()', false);
    }

    public function test_storage_failure_rolls_back_revision_intervals_and_dates_with_safe_errors(): void
    {
        $owner = $this->account(RoleName::Provider); $this->service($owner);
        app(ProviderAvailability::class)->save($owner, $this->availabilityData());
        $before = ProviderAvailabilitySetting::sole()->getAttributes();
        $rows = DB::table('provider_weekly_intervals')->get()->toArray(); $dates = DB::table('provider_blocked_dates')->get()->toArray();
        DB::unprepared("CREATE TRIGGER fail_availability BEFORE INSERT ON provider_blocked_dates BEGIN SELECT RAISE(ABORT, 'Simulated failure'); END");
        Log::spy();
        $this->actingAs($owner, 'web')->patchJson(route('availability.update'), $this->availabilityData(['revision' => 1, 'timezone' => 'UTC']))->assertStatus(503)->assertDontSee('INSERT')->assertDontSee('Simulated failure');
        Log::shouldNotHaveReceived('error');
        $this->assertSame($before, ProviderAvailabilitySetting::sole()->getAttributes());
        $this->assertEquals($rows, DB::table('provider_weekly_intervals')->get()->toArray()); $this->assertEquals($dates, DB::table('provider_blocked_dates')->get()->toArray());
    }

    public function test_rate_limit_blocks_the_eleventh_edit_without_advancing_revision(): void
    {
        $owner = $this->account(RoleName::Provider); $this->service($owner); $this->actingAs($owner, 'web');
        for ($revision = 0; $revision < 10; $revision++) $this->patch(route('availability.update'), $this->availabilityData(['revision' => $revision]))->assertStatus(303);
        $this->patch(route('availability.update'), $this->availabilityData(['revision' => 10]))->assertStatus(429)->assertHeader('Retry-After');
        $this->assertSame(10, ProviderAvailabilitySetting::sole()->revision);
    }
}
