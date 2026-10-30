<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\InquiryNotification;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Tests\Support\EnforcedCsrf;
use Tests\Support\InquiryFixtures;
use Tests\TestCase;

class InquiryNotificationCsrfTest extends TestCase
{
    use InquiryFixtures;

    public function test_read_requires_real_csrf_and_a_valid_token_never_authorizes_foreign_notices(): void
    {
        $this->app->bind(ValidateCsrfToken::class, EnforcedCsrf::class);
        $provider = $this->account(RoleName::Provider);
        $this->inquiry(null, $provider);
        $notice = InquiryNotification::sole();
        $this->actingAs($provider, 'web')->withSession(['_token' => 'notice-token']);
        $this->get(route('notifications.index'))->assertOk()->assertSee('name="_token"', false);
        $this->post(route('notifications.read', $notice))->assertStatus(419);
        $this->post(route('notifications.read', $notice), ['_token' => 'invalid'])->assertStatus(419);
        $this->assertNull($notice->fresh()->read_at);
        $this->actingAs($this->account(RoleName::Admin), 'web')->post(route('notifications.read', $notice), ['_token' => 'notice-token'])->assertNotFound();
        $this->assertNull($notice->fresh()->read_at);
        $this->actingAs($provider, 'web')->post(route('notifications.read', $notice), ['_token' => 'notice-token'])->assertStatus(303);
        $originalReadAt = $notice->fresh()->getRawOriginal('read_at');
        $this->travel(1)->hour();
        $this->post(route('notifications.read', $notice), ['_token' => 'notice-token'])->assertStatus(303);
        $this->assertSame($originalReadAt, $notice->fresh()->getRawOriginal('read_at'));
        $this->assertDatabaseCount('inquiry_notifications', 1);
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
    }
}
