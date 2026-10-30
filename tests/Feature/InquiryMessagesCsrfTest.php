<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\ServiceInquiryMessage;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Tests\Support\EnforcedCsrf;
use Tests\Support\MessageFixtures;
use Tests\TestCase;

class InquiryMessagesCsrfTest extends TestCase
{
    use MessageFixtures;

    public function test_real_csrf_is_required_and_a_valid_token_never_replaces_participant_authorization(): void
    {
        $this->app->bind(ValidateCsrfToken::class, EnforcedCsrf::class);
        $client = $this->account(RoleName::Client);
        $inquiry = $this->inquiry($client);
        $this->actingAs($client, 'web')->withSession(['_token' => 'message-csrf']);
        $form = $this->get(route('inquiries.show', $inquiry))->assertOk()->assertSee('name="_token"', false);
        preg_match('/name="idempotency_key" value="([^"]+)"/', $form->getContent(), $matches);
        $this->assertNotEmpty($matches[1]);
        $payload = $this->messageData('Mensaje enviado desde el formulario real.', $matches[1]);
        $this->post(route('inquiries.messages.store', $inquiry), $payload)->assertStatus(419);
        $this->post(route('inquiries.messages.store', $inquiry), $payload + ['_token' => 'wrong'])->assertStatus(419);
        $this->assertDatabaseCount('service_inquiry_messages', 0);
        $this->actingAs($this->account(RoleName::Admin, RoleName::Client, RoleName::Provider), 'web')
            ->post(route('inquiries.messages.store', $inquiry), $payload + ['_token' => 'message-csrf'])->assertForbidden();
        $this->actingAs($client, 'web')->post(route('inquiries.messages.store', $inquiry), $payload + ['_token' => 'message-csrf'])->assertStatus(303);
        $before = ServiceInquiryMessage::sole()->getAttributes();
        $this->post(route('inquiries.messages.store', $inquiry), $payload + ['_token' => 'message-csrf'])->assertStatus(303);
        $this->assertSame($before, ServiceInquiryMessage::sole()->getAttributes());
        $this->get(route('inquiries.messages.store', $inquiry))->assertStatus(405);
        $this->assertDatabaseCount('service_inquiry_messages', 1);
        $this->assertDatabaseCount('inquiry_notifications', 1);
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
    }
}
