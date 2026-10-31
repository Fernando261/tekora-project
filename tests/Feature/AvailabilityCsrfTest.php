<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Tests\Support\AvailabilityFixtures;
use Tests\Support\EnforcedCsrf;
use Tests\TestCase;

class AvailabilityCsrfTest extends TestCase
{
    use AvailabilityFixtures;

    public function test_real_csrf_and_current_owner_permission_are_required_for_every_edit(): void
    {
        $this->app->bind(ValidateCsrfToken::class, EnforcedCsrf::class);
        $owner = $this->account(RoleName::Provider); $service = $this->service($owner);
        $this->get(route('availability.edit'))->assertRedirect('/login');
        $this->actingAs($owner, 'web')->withSession(['_token' => 'availability-token'])->get(route('availability.edit'))->assertOk()->assertSee('name="_token"', false)->assertSee('name="_method" value="PATCH"', false);
        $this->patch(route('availability.update'), $this->availabilityData())->assertStatus(419);
        $this->patch(route('availability.update'), $this->availabilityData() + ['_token' => 'wrong'])->assertStatus(419);
        $this->actingAs($this->account(RoleName::Admin), 'web')->patch(route('availability.update'), $this->availabilityData() + ['_token' => 'availability-token'])->assertForbidden();
        $this->actingAs($owner, 'web')->patchJson(route('availability.update'), $this->availabilityData(['provider_profile_id' => 999]) + ['_token' => 'availability-token'])->assertUnprocessable();
        $this->assertDatabaseCount('provider_availability_settings', 0);
        $this->patch(route('availability.update'), $this->availabilityData() + ['_token' => 'availability-token'])->assertStatus(303);
        $this->assertDatabaseCount('provider_availability_settings', 1);
        $this->assertDatabaseCount('citas', 0);
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
    }
}
