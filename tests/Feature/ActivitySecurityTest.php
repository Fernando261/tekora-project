<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\ActivityNotification;
use Tests\Support\Retired\Profesionista;
use App\Services\NotificationInbox;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Hash;
use Tests\Support\EnforcedCsrf;
use Tests\Support\ReviewFixtures;
use Tests\TestCase;

class ActivitySecurityTest extends TestCase
{
    use ReviewFixtures;

    public function test_activity_reads_are_private_and_admin_has_no_implicit_access(): void
    {
        [$client, $provider] = $this->reviewContext(); $notice = ActivityNotification::where('user_id', $provider->id)->sole();
        $this->get(route('notifications.index'))->assertRedirect('/login'); $this->postJson(route('notifications.activity.read', $notice))->assertUnauthorized();
        foreach ([$client, $this->account(RoleName::Client, RoleName::Provider), $this->account(RoleName::Admin)] as $stranger) {
            $this->actingAs($stranger)->post(route('notifications.activity.read', $notice))->assertNotFound();
            $this->get(route('notifications.index', ['user_id' => $provider->id]))->assertOk()->assertDontSee('action="'.route('notifications.activity.read', $notice).'"', false);
        }
        $this->actingAs($provider)->post(route('notifications.activity.read', '9999999999999999999999999999999'))->assertNotFound();
        $this->get(route('notifications.activity.read', $notice))->assertStatus(405);
        $this->assertNull($notice->fresh()->read_at);
    }

    public function test_real_csrf_protects_both_read_routes_and_native_forms_work_without_scripts(): void
    {
        [$client, $provider] = $this->reviewContext(); $this->app->bind(ValidateCsrfToken::class, EnforcedCsrf::class);
        $notice = ActivityNotification::where('user_id', $provider->id)->sole(); $old = $provider->inquiryNotifications()->sole();
        $this->actingAs($provider)->withSession(['_token' => 'activity-csrf'])->get(route('notifications.index'))->assertOk()->assertSee('method="POST"', false)->assertSee('name="_token"', false)->assertDontSee('onclick=', false);
        foreach ([route('notifications.activity.read', $notice), route('notifications.read', $old)] as $url) {
            $this->post($url)->assertStatus(419); $this->post($url, ['_token' => 'wrong'])->assertStatus(419);
            $this->post($url, ['_token' => 'activity-csrf'])->assertStatus(303);
        }
        $this->assertNotNull($notice->fresh()->read_at); $this->assertNotNull($old->fresh()->read_at);
    }

    public function test_payload_cannot_create_notifications_or_forge_read_identity_and_dates(): void
    {
        [$client, $provider] = $this->reviewContext(); $notice = ActivityNotification::where('user_id', $provider->id)->sole();
        $this->actingAs($provider)->postJson(route('notifications.activity.read', $notice), ['user_id' => $client->id, 'event' => 'review_published',
            'service_review_id' => 999, 'read_at' => '2030-01-01', 'created_at' => '2030-01-01', 'actor_user_id' => $client->id])->assertUnprocessable();
        $this->assertNull($notice->fresh()->read_at); $this->assertSame($provider->id, $notice->fresh()->user_id);
        $this->post(route('notifications.index'))->assertStatus(405);
        $this->assertDatabaseCount('activity_notifications', 3);
        foreach (['page=-1', 'page[]=1', 'page=10001'] as $query) $this->getJson('/cuenta/notificaciones?'.$query)->assertUnprocessable();
        $this->get(route('notifications.index', ['page' => 2]))->assertNotFound();
    }

    public function test_rate_limit_is_scoped_to_account_and_does_not_affect_the_old_read_route(): void
    {
        [$client, $provider] = $this->reviewContext(); $notice = ActivityNotification::where('user_id', $provider->id)->sole();
        for ($i = 0; $i < 30; $i++) $this->actingAs($provider)->post(route('notifications.activity.read', $notice))->assertStatus(303);
        $this->postJson(route('notifications.activity.read', $notice))->assertStatus(429)->assertHeader('Retry-After');
        $this->post(route('notifications.read', $provider->inquiryNotifications()->sole()))->assertStatus(303);
        $other = ActivityNotification::where('user_id', $client->id)->firstOrFail();
        $this->actingAs($client)->post(route('notifications.activity.read', $other))->assertStatus(303);
    }

    public function test_feed_displays_mexico_city_time_independently_of_application_timezone(): void
    {
        [$client, $provider] = $this->reviewContext();
        $this->actingAs($client)->get(route('notifications.index'))->assertOk()->assertSee('07/01/2030 10:00');
        $previous = date_default_timezone_get(); config(['app.timezone' => 'Asia/Tokyo']); date_default_timezone_set('Asia/Tokyo');
        try { $this->get(route('notifications.index'))->assertOk()->assertSee('07/01/2030 10:00')->assertSee('2030-01-07T16:00:00+00:00'); }
        finally { date_default_timezone_set($previous); }
    }

    public function test_legacy_guard_and_matching_email_do_not_access_central_notifications(): void
    {
        [$client, $provider] = $this->reviewContext(); $notice = ActivityNotification::where('user_id', $provider->id)->sole();
        $legacy = Profesionista::create(['nombre' => 'Profesional anterior', 'email' => $provider->email, 'cedula' => 'ACTIVITY-LEGACY', 'password' => Hash::make('separate-password'), 'google_access_token' => 'fake-preserved-token']);
        $before = $legacy->fresh()->getAttributes();
        $this->actingAs($legacy, 'retired-fixture');
        $this->post('/login-profesionista')->assertNotFound();
        $this->get(route('notifications.index'))->assertRedirect('/login'); $this->postJson(route('notifications.activity.read', $notice))->assertUnauthorized();
        $this->assertSame($before, $legacy->fresh()->getAttributes()); $this->assertGuest('web'); $this->assertNull($notice->fresh()->read_at);
    }
}
