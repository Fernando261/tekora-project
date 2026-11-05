<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Exceptions\InquiryConflict;
use App\Exceptions\InquiryMessageConflict;
use App\Exceptions\InquiryMessageUnavailable;
use App\Exceptions\InquiryReadFailure;
use App\Exceptions\ReservationFailure;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\MobileOperationsFixtures;
use Tests\TestCase;

#[Group('mobile-operations')]
class MobileOperationsContractTest extends TestCase
{
    use MobileOperationsFixtures;

    public function test_all_thirteen_operations_require_bearer_and_current_client_permissions(): void
    {
        $client = $this->account(RoleName::Client);
        $wrong = $client->createToken('mobile:wrong', ['provider:write'], now()->addDay())->plainTextToken;
        $operations = [['get', 'inquiries'], ['post', 'services/1/inquiries'], ['get', 'inquiries/1'], ['patch', 'inquiries/1/cancel'],
            ['get', 'conversations'], ['get', 'inquiries/1/messages'], ['post', 'inquiries/1/messages'], ['post', 'inquiries/1/messages/read'],
            ['get', 'inquiries/1/reservation-options'], ['post', 'inquiries/1/reservations'], ['get', 'reservations'], ['get', 'reservations/1'], ['patch', 'reservations/1/cancel']];
        foreach ($operations as [$method, $path]) {
            Auth::forgetGuards();
            $this->json(strtoupper($method), '/api/v1/'.$path)->assertUnauthorized()->assertJsonPath('error.code', 'unauthenticated');
            Auth::forgetGuards();
            $this->json(strtoupper($method), '/api/v1/'.$path, [], ['Authorization' => 'Bearer '.$wrong])->assertForbidden();
        }
    }

    public function test_all_workflow_failures_use_safe_json_even_without_accept_and_in_debug_mode(): void
    {
        config(['app.debug' => true]);
        foreach ([new InquiryConflict(1, 'PRIVATE /server/file'), new InquiryMessageConflict('PRIVATE'), new InquiryMessageUnavailable,
            new InquiryReadFailure(409), new InquiryReadFailure(503), new ReservationFailure('PRIVATE', 409), new ReservationFailure('PRIVATE', 503)] as $i => $failure) {
            $status = match (true) {
                $failure instanceof InquiryMessageUnavailable => 503,
                $failure instanceof InquiryReadFailure, $failure instanceof ReservationFailure => $failure->status,
                default => 409,
            };
            Route::get('/api/v1/_test/failure'.$i, fn () => throw $failure);
            $response = $this->get('/api/v1/_test/failure'.$i, ['Accept' => 'text/html'])->assertStatus($status)->assertHeader('Content-Type', 'application/json')
                ->assertJsonStructure(['error' => ['code', 'message', 'fields']]);
            foreach (['PRIVATE', 'server/file', 'inquiry_url', 'exception', 'trace', '<html'] as $secret) {
                $this->assertStringNotContainsString($secret, $response->getContent());
            }
        }
    }

    public function test_a_later_arrival_is_not_marked_read_by_a_previous_page_token(): void
    {
        $inquiry = $this->inquiry();
        $client = $inquiry->client;
        $provider = $inquiry->service->providerProfile->user;
        $first = $this->message($provider, $inquiry);
        $url = '/api/v1/inquiries/'.$inquiry->id.'/messages';
        $page = $this->getJson($url, $this->bearer($client))->assertOk();
        $later = $this->message($provider, $inquiry, 'Mensaje que llegó después de mostrar la página.');
        $this->postJson($url.'/read', ['read_token' => $page->json('meta.read_token')], $this->bearer($client))->assertOk()->assertJsonPath('data.last_read_message_id', $first->id);
        $this->getJson($url, $this->bearer($client))->assertOk()->assertJsonPath('meta.unread_messages_count', 1)->assertJsonPath('data.0.id', $later->id);
    }

    public function test_reservation_rate_limit_and_read_rate_limit_are_per_mobile_actor(): void
    {
        $inquiry = $this->inquiry();
        $client = $inquiry->client;
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/v1/inquiries/'.$inquiry->id.'/reservations', $this->reservationData(), $this->bearer($client))->assertConflict();
        }
        $this->postJson('/api/v1/inquiries/'.$inquiry->id.'/reservations', $this->reservationData(), $this->bearer($client))->assertStatus(429);
        for ($i = 0; $i < 30; $i++) {
            $this->postJson('/api/v1/inquiries/'.$inquiry->id.'/messages/read', ['read_token' => 'bad'], $this->bearer($client))->assertUnprocessable();
        }
        $this->postJson('/api/v1/inquiries/'.$inquiry->id.'/messages/read', ['read_token' => 'bad'], $this->bearer($client))->assertStatus(429);
    }
}
