<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\ProviderAvailabilitySetting;
use App\Services\ProviderAvailability;
use Illuminate\Support\Carbon;
use Tests\Support\AvailabilityFixtures;
use Tests\TestCase;

class AvailabilityPublicAndDatesTest extends TestCase
{
    use AvailabilityFixtures;

    public function test_public_service_and_provider_show_unpublished_until_the_owner_explicitly_saves(): void
    {
        $owner = $this->account(RoleName::Provider); $service = $this->service($owner);
        foreach ([route('catalog.show', $service), route('public-providers.show', $service->provider_profile_id)] as $url) {
            $this->get($url)->assertOk()->assertSee('Horario no publicado')->assertSee('Horarios orientativos, sujetos a confirmación con el prestador');
        }
        $this->assertDatabaseCount('provider_availability_settings', 0);
        $this->assertDatabaseCount('provider_weekly_intervals', 0);
    }

    public function test_public_schedule_is_informational_local_and_excludes_old_blocked_dates_and_private_identity(): void
    {
        Carbon::setTestNow('2029-12-31 18:00:00 UTC');
        $owner = $this->account(RoleName::Provider); $owner->update(['email' => 'private-zone@example.test']);
        $service = $this->service($owner);
        app(ProviderAvailability::class)->save($owner, $this->availabilityData(['blocked_dates' => ['2029-12-30', '2030-01-01']]));
        foreach ([route('catalog.show', $service), route('public-providers.show', $service->provider_profile_id)] as $url) {
            $this->get($url)->assertOk()->assertSee('America/Mexico_City')->assertSee('09:00–12:00')->assertSee('13:00–17:00')->assertSee('Sin atención')
                ->assertSee('01/01/2030')->assertDontSee('30/12/2029')->assertDontSee('private-zone@example.test')->assertDontSee($owner->password)
                ->assertDontSee('name="starts_at"', false)->assertDontSee('name="start_datetime"', false);
        }
        $this->assertDatabaseCount('provider_blocked_dates', 2);
        $this->assertDatabaseCount('citas', 0);
    }

    public function test_paused_draft_inactive_and_role_revoked_services_cannot_publish_availability(): void
    {
        $owner = $this->account(RoleName::Provider); $service = $this->service($owner);
        app(ProviderAvailability::class)->save($owner, $this->availabilityData());
        foreach (['draft', 'paused'] as $status) {
            $service->update(['status' => $status]);
            $this->get(route('catalog.show', $service))->assertNotFound();
            $this->get(route('public-providers.show', $service->provider_profile_id))->assertNotFound();
            $this->assertNull(app(ProviderAvailability::class)->publicFor($service->providerProfile));
        }
        $other = $this->service($owner);
        $this->get(route('public-providers.show', $service->provider_profile_id))->assertOk()->assertSee('09:00–12:00');
        $this->get(route('catalog.show', $service))->assertNotFound();
        $other->category->update(['is_active' => false]);
        $this->get(route('catalog.show', $other))->assertNotFound();
        $other->category->update(['is_active' => true]); $owner->roles()->detach();
        $this->get(route('public-providers.show', $service->provider_profile_id))->assertNotFound();
        $this->assertDatabaseCount('provider_availability_settings', 1);
    }

    public function test_civil_dates_use_the_configured_local_day_at_month_and_year_boundaries_without_shifting_hours(): void
    {
        Carbon::setTestNow('2027-01-01 01:00:00 UTC');
        $owner = $this->account(RoleName::Provider); $service = $this->service($owner);
        $data = $this->availabilityData(['blocked_dates' => ['2026-12-31', '2027-01-01', '2027-01-31', '2027-02-01']]);
        app(ProviderAvailability::class)->save($owner, $data);
        $schedule = app(ProviderAvailability::class)->publicFor($service->providerProfile);
        $this->assertSame($data['blocked_dates'], $schedule['blocked_dates']->all());
        app(ProviderAvailability::class)->save($owner, ['revision' => 1, 'timezone' => 'Pacific/Kiritimati'] + array_diff_key($data, ['revision' => 1, 'timezone' => 1]));
        $schedule = app(ProviderAvailability::class)->publicFor($service->providerProfile);
        $this->assertSame(['2027-01-01', '2027-01-31', '2027-02-01'], $schedule['blocked_dates']->all());
        $this->assertSame([540, 780, 600], $owner->providerProfile->weeklyIntervals()->orderBy('day_of_week')->orderBy('start_minute')->pluck('start_minute')->all());
        $this->assertDatabaseCount('provider_blocked_dates', 4);
        $this->get(route('catalog.show', $service))->assertOk()->assertSee('01/02/2027')->assertDontSee('31/12/2026');
    }

    public function test_dst_changes_do_not_convert_weekly_civil_times_into_utc_or_generate_bookable_slots(): void
    {
        $owner = $this->account(RoleName::Provider); $service = $this->service($owner);
        app(ProviderAvailability::class)->save($owner, $this->availabilityData(['timezone' => 'America/New_York',
            'intervals' => [['day_of_week' => 7, 'start' => '02:00', 'end' => '04:00']], 'blocked_dates' => ['2026-03-08', '2026-11-01']]));
        foreach (['2026-03-08 06:30:00 UTC', '2026-03-08 07:30:00 UTC', '2026-11-01 05:30:00 UTC', '2026-11-01 06:30:00 UTC'] as $instant) {
            Carbon::setTestNow($instant);
            $this->get(route('catalog.show', $service))->assertOk()->assertSee('02:00–04:00')->assertSee('America/New_York')->assertSee('01/11/2026');
            $this->assertSame(120, $owner->providerProfile->weeklyIntervals()->sole()->start_minute);
            $this->assertSame(240, $owner->providerProfile->weeklyIntervals()->sole()->end_minute);
        }
        $this->assertSame('UTC', config('app.timezone'));
    }

    public function test_strict_gregorian_dates_reject_bad_days_duplicates_and_excess_and_accept_leap_years(): void
    {
        $owner = $this->account(RoleName::Provider); $this->service($owner); $this->actingAs($owner, 'web');
        \Illuminate\Support\Facades\RateLimiter::for('provider-availability', fn () => \Illuminate\Cache\RateLimiting\Limit::none());
        foreach (['2027-02-29', '2100-02-29', '2026-04-31', '2026-13-01', '2026-00-01', '2026-01-00', '2026-1-1', '01/12/2026', '1999-12-31', '2101-01-01'] as $date) {
            $this->patchJson(route('availability.update'), $this->availabilityData(['blocked_dates' => [$date]]))->assertUnprocessable();
        }
        $this->patchJson(route('availability.update'), $this->availabilityData(['blocked_dates' => ['2030-01-01', '2030-01-01']]))->assertUnprocessable();
        $this->patchJson(route('availability.update'), $this->availabilityData(['blocked_dates' => array_fill(0, 101, '2030-01-01')]))->assertUnprocessable();
        $this->patch(route('availability.update'), $this->availabilityData(['blocked_dates' => "2000-02-29\r\n2028-02-29\n2030-12-31\n2031-01-01\n"]))->assertStatus(303);
        $this->assertSame(['2000-02-29', '2028-02-29', '2030-12-31', '2031-01-01'], $owner->providerProfile->blockedDates()->orderBy('blocked_date')->pluck('blocked_date')->all());
        $this->assertDatabaseCount('provider_blocked_dates', 4);
    }

    public function test_a_saved_empty_week_means_no_attention_not_unpublished_or_free_availability(): void
    {
        $owner = $this->account(RoleName::Provider); $service = $this->service($owner);
        app(ProviderAvailability::class)->save($owner, $this->availabilityData(['intervals' => [], 'blocked_dates' => [], 'timezone' => 'UTC']));
        $response = $this->get(route('catalog.show', $service))->assertOk()->assertDontSee('Horario no publicado')->assertSee('Zona horaria: UTC');
        $this->assertSame(7, substr_count($response->getContent(), '<span>Sin atención</span>'));
        $this->assertSame('UTC', ProviderAvailabilitySetting::sole()->timezone);
    }

    public function test_iana_aliases_are_supported_without_accepting_offsets_or_unknown_factory_zone(): void
    {
        $owner = $this->account(RoleName::Provider); $this->service($owner);
        app(ProviderAvailability::class)->save($owner, $this->availabilityData(['timezone' => 'Etc/UTC']));
        $this->assertSame('Etc/UTC', ProviderAvailabilitySetting::sole()->timezone);
        app(ProviderAvailability::class)->save($owner, $this->availabilityData(['revision' => 1, 'timezone' => 'US/Central']));
        $this->assertSame('US/Central', ProviderAvailabilitySetting::sole()->timezone);
        $this->actingAs($owner, 'web')->patchJson(route('availability.update'), $this->availabilityData(['revision' => 2, 'timezone' => 'Factory']))->assertUnprocessable();
    }
}
