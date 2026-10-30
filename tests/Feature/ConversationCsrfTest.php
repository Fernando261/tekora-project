<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Tests\Support\EnforcedCsrf;
use Tests\Support\ReadFixtures;
use Tests\TestCase;

class ConversationCsrfTest extends TestCase
{
    use ReadFixtures;

    public function test_real_csrf_is_required_and_does_not_authorize_foreign_actions(): void
    {
        $this->app->bind(ValidateCsrfToken::class, EnforcedCsrf::class);
        $client = $this->account(RoleName::Client); $provider = $this->account(RoleName::Provider);
        $inquiry = $this->inquiry($client, $provider); $this->message($provider, $inquiry);
        $page = $this->actingAs($client, 'web')->withSession(['_token' => 'read-csrf'])->get(route('inquiries.show', $inquiry))->assertOk();
        preg_match('/name="read_token" value="([^"]+)"/', $page->getContent(), $matches);
        $this->assertNotEmpty($matches[1]); $payload = ['read_token' => html_entity_decode($matches[1])];
        $this->post(route('conversations.read', $inquiry), $payload)->assertStatus(419);
        $this->post(route('conversations.read', $inquiry), $payload + ['_token' => 'wrong'])->assertStatus(419);
        $this->actingAs($this->account(RoleName::Client, RoleName::Admin), 'web')
            ->post(route('conversations.read', $inquiry), $payload + ['_token' => 'read-csrf'])->assertForbidden();
        $this->assertDatabaseCount('service_inquiry_reads', 0);
        $this->actingAs($client, 'web')->post(route('conversations.read', $inquiry), $payload + ['_token' => 'read-csrf'])->assertStatus(303);
        $this->get(route('conversations.read', $inquiry))->assertStatus(405);
        $this->assertDatabaseCount('service_inquiry_reads', 1);
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
    }
}
