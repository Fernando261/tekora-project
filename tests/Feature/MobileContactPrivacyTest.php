<?php

namespace Tests\Feature;

use App\Enums\InquiryAction;
use App\Enums\RoleName;
use App\Services\InquiryWorkflow;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\MobileOperationsFixtures;
use Tests\TestCase;

#[Group('mobile-operations')]
class MobileContactPrivacyTest extends TestCase
{
    use MobileOperationsFixtures;

    #[DataProvider('contacts')]
    public function test_inquiry_rejects_contact_without_creating_an_inquiry_or_a_notification(string $contact): void
    {
        $client = $this->account(RoleName::Client);
        $service = $this->service($this->account(RoleName::Provider));
        config(['app.debug' => true]);
        Log::spy();

        $response = $this->postJson('/api/v1/services/'.$service->id.'/inquiries', ['message' => $contact], $this->bearer($client));

        $this->assertContactError($response, 'message');
        $this->assertDatabaseCount('service_inquiries', 0);
        $this->assertDatabaseCount('inquiry_notifications', 0);
    }

    #[DataProvider('contacts')]
    public function test_message_rejects_contact_without_changing_the_conversation(string $contact): void
    {
        $inquiry = $this->inquiry();
        $original = $inquiry->getRawOriginal();
        config(['app.debug' => true]);
        Log::spy();

        $response = $this->postJson('/api/v1/inquiries/'.$inquiry->id.'/messages', $this->messageData($contact), $this->bearer($inquiry->client));

        $this->assertContactError($response, 'body');
        $this->assertDatabaseCount('service_inquiry_messages', 0);
        $this->assertSame($original, $inquiry->fresh()->getRawOriginal());
    }

    public static function contacts(): array
    {
        return [
            'teléfono convencional' => ['Puedes llamarme al 5500000000.'],
            'teléfono espaciado' => ['Mi teléfono es 3 3 2 2 3 3 2 2 3 3.'],
            'teléfono disfrazado' => ['Mi WhatsApp es Serie: 3 3 2 2 3 3 2 2 3 3.'],
            'correo directo' => ['Contáctame en prueba@example.test.'],
            'correo con palabras' => ['Escríbeme a usuario arroba dominio punto es.'],
            'enlace de WhatsApp' => ['Envíame un mensaje por https://wa.me/525500000000.'],
            'enlace de Instagram' => ['Mi contacto está en https://ig.me/m/cuenta_sintetica.'],
        ];
    }

    public function test_legitimate_technical_references_keep_creation_and_replay_contracts(): void
    {
        $client = $this->account(RoleName::Client);
        $service = $this->service($this->account(RoleName::Provider));
        $opening = '/api/v1/services/'.$service->id.'/inquiries';
        $description = ['message' => 'Revisa la Serie: 3 3 2 2 3 3 2 2 3 3. Manual en https://docs.example.test/piezas/5500000000.'];
        $created = $this->postJson($opening, $description, $this->bearer($client))
            ->assertCreated()->assertJsonPath('data.message', $description['message']);
        $this->postJson($opening, $description, $this->bearer($client))->assertOk()->assertExactJson($created->json());

        $url = '/api/v1/inquiries/'.$created->json('data.id').'/messages';
        $payload = $this->messageData('El código de seguimiento es 5500000000. Serie: 3 3 2 2 3 3 2 2 3 3.');
        $message = $this->postJson($url, $payload, $this->bearer($client))->assertCreated()
            ->assertJsonPath('data.body', $payload['body'])->assertJsonPath('data.is_mine', true);
        $this->postJson($url, $payload, $this->bearer($client))->assertOk()->assertExactJson($message->json());

        $this->assertDatabaseCount('service_inquiries', 1);
        $this->assertDatabaseCount('inquiry_notifications', 1);
        $this->assertDatabaseCount('service_inquiry_messages', 1);
        $this->assertDatabaseHas('service_inquiry_messages', ['id' => $message->json('data.id'), 'body' => $payload['body']]);
    }

    public function test_author_can_replay_preexisting_contact_even_after_closure(): void
    {
        $inquiry = $this->inquiry();
        $client = $inquiry->client;
        $url = '/api/v1/inquiries/'.$inquiry->id.'/messages';
        $payload = $this->messageData("Detalle original.\nTeléfono: 5500000000 piezas.");
        $stored = $this->message($client, $inquiry, $payload['body'], $payload['idempotency_key'])->refresh();
        $original = $stored->getRawOriginal();
        $resource = ['data' => ['id' => $stored->id, 'inquiry_id' => $inquiry->id, 'is_mine' => true,
            'body' => $stored->body, 'created_at' => $stored->created_at->toISOString()]];

        $this->postJson($url, $payload, $this->bearer($client))->assertOk()->assertExactJson($resource);
        $this->postJson($url, ['body' => 'Mi correo es prueba@example.test.'] + $payload, $this->bearer($client))
            ->assertConflict()->assertJsonPath('error.code', 'conflict')->assertDontSee('prueba@example.test');
        $this->postJson($url, $this->messageData($payload['body']), $this->bearer($client))
            ->assertUnprocessable()->assertJsonPath('error.code', 'validation_failed');
        $otherInquiry = $this->inquiry($client);
        $this->postJson('/api/v1/inquiries/'.$otherInquiry->id.'/messages', $payload, $this->bearer($client))
            ->assertUnprocessable()->assertJsonPath('error.code', 'validation_failed');

        app(InquiryWorkflow::class)->decide($client, $inquiry->id, InquiryAction::Cancel);
        $this->postJson($url, ['body' => ' '.str_replace("\n", "\r\n", $payload['body']).' ',
            'idempotency_key' => strtoupper($payload['idempotency_key'])], $this->bearer($client))
            ->assertOk()->assertExactJson($resource);
        $this->assertDatabaseCount('service_inquiry_messages', 1);
        $this->assertSame($original, $stored->fresh()->getRawOriginal());
    }

    public function test_contact_validation_preserves_authentication_and_conversation_scope(): void
    {
        $inquiry = $this->inquiry();
        $body = 'Escríbeme a prueba@example.test.';
        $payload = $this->messageData($body);
        $this->message($inquiry->client, $inquiry, $body, $payload['idempotency_key']);

        $url = '/api/v1/inquiries/'.$inquiry->id.'/messages';
        $opening = '/api/v1/services/'.$inquiry->service_id.'/inquiries';
        foreach ([$opening => ['message' => $body], $url => $payload] as $endpoint => $data) {
            $this->postJson($endpoint, $data)->assertUnauthorized()->assertJsonPath('error.code', 'unauthenticated');
        }
        $provider = $inquiry->service->providerProfile->user;
        foreach ([$opening => ['message' => $body], $url => $payload] as $endpoint => $data) {
            $this->postJson($endpoint, $data, $this->bearer($provider))->assertForbidden()->assertJsonPath('error.code', 'forbidden');
        }

        $outsider = $this->account(RoleName::Client);
        $foreign = $this->postJson($url, $payload, $this->bearer($outsider))->assertNotFound()
            ->assertJsonPath('error.code', 'not_found')->assertDontSee('prueba@example.test');
        $this->postJson('/api/v1/inquiries/999999/messages', $payload, $this->bearer($outsider))
            ->assertNotFound()->assertExactJson($foreign->json());
        $this->assertDatabaseCount('service_inquiries', 1);
        $this->assertDatabaseCount('service_inquiry_messages', 1);
    }

    private function assertContactError(TestResponse $response, string $field): void
    {
        $response->assertUnprocessable()->assertHeader('X-Content-Type-Options', 'nosniff')->assertExactJson([
            'error' => [
                'code' => 'validation_failed',
                'message' => 'Los datos enviados no son válidos.',
                'fields' => [$field => ['Por seguridad, no compartas números de teléfono, correos electrónicos ni enlaces de contacto. Mantén la conversación dentro de Tekora.']],
            ],
        ]);
        Log::shouldNotHaveReceived('error');
        Log::shouldNotHaveReceived('warning');
    }
}
