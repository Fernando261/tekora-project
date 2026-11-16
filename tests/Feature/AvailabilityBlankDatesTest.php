<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\ProviderAvailabilitySetting;
use App\Services\ProviderAvailability;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AvailabilityFixtures;
use Tests\TestCase;

class AvailabilityBlankDatesTest extends TestCase
{
    use AvailabilityFixtures;

    public static function blankDates(): array
    {
        return ['empty textarea' => [''], 'whitespace textarea' => [" \n "], 'normalized textarea' => [null]];
    }

    #[DataProvider('blankDates')]
    public function test_blank_dates_publish_a_schedule_and_clear_previous_exceptions(?string $blank): void
    {
        $owner = $this->account(RoleName::Provider);
        $service = $this->service($owner);
        $this->actingAs($owner, 'web')
            ->from(route('availability.edit'))
            ->patch(route('availability.update'), $this->availabilityData(['blocked_dates' => $blank]))
            ->assertStatus(303)
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $this->assertSame(1, ProviderAvailabilitySetting::sole()->revision);
        $this->assertDatabaseCount('provider_weekly_intervals', 3);
        $this->assertDatabaseCount('provider_blocked_dates', 0);
        $this->get(route('public-providers.show', $service->provider_profile_id))
            ->assertOk()->assertSee('09:00–12:00');

        app(ProviderAvailability::class)->save($owner, $this->availabilityData(['revision' => 1]));
        $this->assertDatabaseCount('provider_blocked_dates', 2);
        $this->patch(route('availability.update'), $this->availabilityData(['revision' => 2, 'blocked_dates' => $blank]))
            ->assertStatus(303)->assertSessionHasNoErrors();
        $this->assertSame(3, ProviderAvailabilitySetting::sole()->revision);
        $this->assertDatabaseCount('provider_weekly_intervals', 3);
        $this->assertDatabaseCount('provider_blocked_dates', 0);
    }

    public function test_nonempty_invalid_date_values_still_reject_the_entire_schedule(): void
    {
        $owner = $this->account(RoleName::Provider);
        $this->service($owner);
        $this->actingAs($owner, 'web');

        foreach ([[123, 'blocked_dates'], [false, 'blocked_dates'], [['not-a-date'], 'blocked_dates.0']] as [$invalid, $field]) {
            $this->patchJson(route('availability.update'), $this->availabilityData(['blocked_dates' => $invalid]))
                ->assertUnprocessable()->assertJsonValidationErrors($field);
            $this->assertDatabaseCount('provider_availability_settings', 0);
            $this->assertDatabaseCount('provider_weekly_intervals', 0);
        }
    }
}
