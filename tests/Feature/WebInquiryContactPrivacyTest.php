<?php

namespace Tests\Feature;

use App\Enums\InquiryStatus;
use App\Enums\RoleName;
use App\Services\InquiryMessaging;
use Illuminate\Support\Str;
use Tests\Support\MobileOperationsFixtures;
use Tests\TestCase;

class WebInquiryContactPrivacyTest extends TestCase
{
    use MobileOperationsFixtures;

    public function test_initial_message_rejects_email_shows_error_and_preserves_submitted_text(): void
    {
        $client = $this->account(RoleName::Client);
        $service = $this->service($this->account(RoleName::Provider));
        $body = 'El equipo falla. Mi correo es fixture@example.test. <equipo>';
        $form = route('inquiries.create', $service);
        $store = route('inquiries.store', $service);

        $this->actingAs($client, 'web')->from($form)->post($store, ['message' => $body])
            ->assertRedirect($form)->assertSessionHasErrors('message')->assertSessionHasInput('message', $body);
        $this->assertDatabaseCount('service_inquiries', 0);
        $this->assertDatabaseCount('inquiry_notifications', 0);

        $this->get($form)->assertOk()->assertSee('Mantén la conversación dentro de Tekora.')
            ->assertSee(e($body), false)->assertDontSee($body, false)->assertSee('aria-invalid="true"', false);
        $this->postJson($store, ['message' => $body])->assertUnprocessable()->assertJsonValidationErrors('message')
            ->assertDontSee('fixture@example.test');

        $allowed = 'El código de seguimiento es 5500000000. Serie: 525500000000. '
            .'Consulta https://docs.example.test/piezas/5500000000. Horario de 10:00 a 11:00. Presupuesto: $1,500 MXN.';
        $this->post($store, ['message' => $allowed])->assertStatus(303);
        $this->assertDatabaseHas('service_inquiries', ['message' => $allowed]);
        $this->assertDatabaseCount('service_inquiries', 1);
    }

    public function test_client_and_provider_share_the_same_rule_without_changing_an_existing_inquiry_or_reservation(): void
    {
        $client = $this->account(RoleName::Client);
        $provider = $this->account(RoleName::Provider);
        $inquiry = $this->inquiry($client, $provider, InquiryStatus::Accepted);
        $reservation = $this->performInquiryReservation($inquiry);
        $originalInquiry = $inquiry->fresh()->getAttributes();
        $originalReservation = $reservation->fresh()->getAttributes();
        $show = route('inquiries.show', $inquiry);
        $store = route('inquiries.messages.store', $inquiry);

        foreach ([$client, $provider] as $actor) {
            $key = (string) Str::uuid();
            $body = 'Teléfono: 5500000000 piezas. <equipo>';
            $this->actingAs($actor, 'web')->from($show)->post($store, ['body' => $body, 'idempotency_key' => $key])
                ->assertRedirect($show)->assertSessionHasErrors('body')
                ->assertSessionHasInput('body', $body)->assertSessionHasInput('idempotency_key', $key);
            $this->get($show)->assertOk()->assertSee('Mantén la conversación dentro de Tekora.')
                ->assertSee(e($body), false)->assertDontSee($body, false)->assertSee('value="'.$key.'"', false);
            $this->assertDatabaseMissing('service_inquiry_messages', ['body' => $body]);
        }

        $key = (string) Str::uuid();
        $this->postJson($store, ['body' => 'Escríbeme por ig.me/m/fixture_contacto.', 'idempotency_key' => $key])
            ->assertUnprocessable()->assertJsonValidationErrors('body')->assertDontSee('fixture_contacto');
        $this->assertDatabaseCount('service_inquiry_messages', 0);

        $allowed = ['body' => "Revisaré el módulo técnico mañana 🛠️.\nTrae 4 piezas. Serie: 525500000000. https://www.facebook.com/privacy/policy/", 'idempotency_key' => $key];
        $this->post($store, $allowed)->assertStatus(303)->assertSessionHas('success');
        $this->post($store, $allowed)->assertStatus(303)->assertSessionHas('info');
        $this->assertDatabaseCount('service_inquiry_messages', 1);
        $this->assertDatabaseHas('service_inquiry_messages', ['body' => $allowed['body']]);
        $this->assertSame($originalInquiry, $inquiry->fresh()->getAttributes());
        $this->assertSame($originalReservation, $reservation->fresh()->getAttributes());

        $this->actingAs($this->account(RoleName::Client), 'web')->get($show)->assertForbidden();
        $this->postJson($store, $allowed)->assertForbidden();
        $this->assertDatabaseCount('service_inquiry_messages', 1);
    }

    public function test_author_can_replay_an_exact_historical_message_without_creating_a_new_one(): void
    {
        $client = $this->account(RoleName::Client);
        $inquiry = $this->inquiry($client);
        $store = route('inquiries.messages.store', $inquiry);
        $show = route('inquiries.show', $inquiry);
        $payload = ['body' => "Equipo de prueba.\nTeléfono: 5500000000 piezas.", 'idempotency_key' => (string) Str::uuid()];
        $historical = app(InquiryMessaging::class)->send($client, $inquiry->id, $payload['body'], $payload['idempotency_key'])['message'];
        $original = $historical->refresh()->getAttributes();

        $this->actingAs($client, 'web')->post($store, $payload)
            ->assertStatus(303)->assertRedirect($show.'#conversacion')->assertSessionHas('info');
        $this->postJson($store, array_replace($payload, ['body' => 'Teléfono: 5500000001 piezas.']))
            ->assertConflict()->assertDontSee('5500000000')->assertDontSee('5500000001');
        $new = array_replace($payload, ['idempotency_key' => (string) Str::uuid()]);
        $this->from($show)->post($store, $new)->assertRedirect($show)->assertSessionHasErrors('body')
            ->assertSessionHasInput('body', $new['body'])->assertSessionHasInput('idempotency_key', $new['idempotency_key']);

        $this->patch(route('inquiries.cancel', $inquiry))->assertStatus(303);
        $this->post($store, [
            'body' => ' '.str_replace("\n", "\r\n", $payload['body']).' ',
            'idempotency_key' => strtoupper($payload['idempotency_key']),
        ])->assertStatus(303)->assertSessionHas('info');
        $this->assertSame($original, $historical->fresh()->getAttributes());
        $this->assertDatabaseCount('service_inquiry_messages', 1);
    }

    public function test_historical_key_does_not_bypass_validation_for_other_authors_or_inquiries(): void
    {
        $client = $this->account(RoleName::Client);
        $provider = $this->account(RoleName::Provider);
        $inquiry = $this->inquiry($client, $provider);
        $otherInquiry = $this->inquiry($client, $provider);
        $payload = ['body' => 'Teléfono: 5500000000 piezas.', 'idempotency_key' => (string) Str::uuid()];
        app(InquiryMessaging::class)->send($client, $inquiry->id, $payload['body'], $payload['idempotency_key']);
        $store = route('inquiries.messages.store', $inquiry);

        $this->actingAs($provider, 'web')->postJson($store, $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('body')->assertDontSee('5500000000');
        $this->actingAs($client, 'web')->postJson(route('inquiries.messages.store', $otherInquiry), $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('body')->assertDontSee('5500000000');
        $this->actingAs($this->account(RoleName::Client), 'web')->postJson($store, $payload)
            ->assertForbidden()->assertDontSee('5500000000');
        $this->assertDatabaseCount('service_inquiry_messages', 1);
    }
}
