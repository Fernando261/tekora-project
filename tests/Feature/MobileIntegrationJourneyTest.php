<?php

namespace Tests\Feature;

use App\Enums\InquiryAction;
use App\Enums\RoleName;
use App\Http\Middleware\MobileClient;
use App\Models\ServiceInquiry;
use App\Models\User;
use App\Notifications\MobileIdentityCode;
use App\Payments\ServicePricing;
use App\Services\InquiryWorkflow;
use App\Services\ReservationWorkflow;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\IdentityPhotoFixtures;
use Tests\Support\MessageFixtures;
use Tests\Support\SlotHoldFixtures;
use Tests\TestCase;

final class MobileIntegrationJourneyTest extends TestCase
{
    use IdentityPhotoFixtures, MessageFixtures, SlotHoldFixtures;

    public function test_solicitant_contracts_from_registration_to_withdrawn_review_and_logout(): void
    {
        $this->travelTo(CarbonImmutable::parse('2030-01-01 12:00:00', 'UTC'));
        [$client, $headers] = $this->registerAndLogin();
        $this->get('/api/v1/me', $headers)->assertOk()->assertJsonPath('data.id', $client->id);
        $this->get('/api/v1/me/profile', $headers)->assertOk()->assertJsonPath('data.photo_url', null);
        $this->patchJson('/api/v1/me/profile', ['name' => 'Solicitante M3.1'], $headers)->assertOk()
            ->assertJsonPath('data.name', 'Solicitante M3.1')->assertJsonPath('data.email', $client->email);
        $this->checkPhoto($headers);

        // Use the provider's existing workflows and a second client, including a dual-role account.
        $provider = $this->account(RoleName::Provider, RoleName::Client);
        $service = $this->service($provider);
        $this->publishSchedule($provider);
        app(ServicePricing::class)->publish($provider, $service->id, [
            'amount_cents' => 100000, 'expected_version' => 0, 'idempotency_key' => (string) Str::uuid(),
        ]);
        $providerPath = '/api/v1/providers/'.$service->provider_profile_id;
        $servicePath = '/api/v1/services/'.$service->id;
        $this->get('/api/v1/categories')->assertOk()->assertJsonPath('data.0.slug', $service->category->slug);
        $this->get('/api/v1/services?category='.$service->category->slug.'&modality=remote&q=computadoras&per_page=1')
            ->assertOk()->assertJsonPath('data.0.id', $service->id)->assertJsonPath('meta.total', 1);
        $this->get($servicePath)->assertOk()->assertJsonPath('data.price.amount.total_cents', 100000);
        $this->get('/api/v1/providers')->assertOk()->assertJsonPath('data.0.id', $service->provider_profile_id);
        $this->get($providerPath)->assertOk()->assertJsonPath('data.id', $service->provider_profile_id)->assertDontSee($provider->email);
        $this->get($providerPath.'/services?page=1&per_page=1')->assertOk()->assertJsonPath('data.0.id', $service->id);
        $this->get($providerPath.'/reviews')->assertOk()->assertJsonPath('meta.total', 0);
        $this->get($servicePath.'/availability')->assertOk()->assertJsonPath('data.timezone', 'America/Mexico_City');

        $message = ['message' => 'Necesito un diagnóstico de mi computadora.'];
        $id = $this->postJson($servicePath.'/inquiries', $message, $headers)->assertCreated()
            ->assertJsonPath('data.status', 'pending')->json('data.id');
        $this->postJson($servicePath.'/inquiries', $message, $headers)->assertOk()->assertJsonPath('data.id', $id);
        $this->assertDatabaseCount('service_inquiries', 1);
        $inquiry = $client->serviceInquiries()->findOrFail($id);
        $path = '/api/v1/inquiries/'.$id;
        $this->get($path, $headers)->assertOk()->assertJsonPath('data.id', $id);
        $this->get($path.'/journey', $headers)->assertOk()->assertJsonPath('data.viewer_role', 'client')
            ->assertJsonPath('data.inquiry.status', 'pending');
        $workflow = app(InquiryWorkflow::class);
        $workflow->decide($provider, $id, InquiryAction::Accept);

        $this->checkConversation($headers, $path, $inquiry, $provider);
        $this->checkNotices($headers, $id);
        $reservationPath = $this->confirmBooking($headers, $path, $provider);

        // Provider participation never grants visibility through any mobile client lookup.
        $this->assertForeignAccessIsHidden($path, $reservationPath, $this->bearer($provider));
        $other = $this->account(RoleName::Client);
        $this->assertForeignAccessIsHidden($path, $reservationPath, $this->bearer($other));
        Auth::forgetGuards();
        $reservation = $this->get($reservationPath, $headers)->assertOk()->assertJsonPath('data.status', 'confirmed')->json('data');
        $this->get('/api/v1/reservations?status=confirmed&per_page=1', $headers)->assertOk()
            ->assertJsonPath('data.0.id', $reservation['id']);
        $this->get('/api/v1/me/dashboard', $headers)->assertOk()->assertJsonPath('data.upcoming_reservations.0.id', $reservation['id'])
            ->assertJsonPath('data.counts.confirmed', 1)->assertJsonPath('data.counts.held', 0)
            ->assertJsonPath('data.counts.unread_conversations', 0);

        $this->travelTo(CarbonImmutable::parse($reservation['ends_at']));
        $this->get($reservationPath, $headers)->assertOk()->assertJsonPath('data.status', 'confirmed');
        $this->postJson($reservationPath.'/review', ['rating' => 5], $headers)->assertConflict();
        app(ReservationWorkflow::class)->decide($provider, $reservation['id'], 'complete', $this->holdInput(['version' => $reservation['version']]));
        $workflow->decide($provider, $id, InquiryAction::Complete);
        $this->get($path.'/journey', $headers)->assertOk()->assertJsonPath('data.closure.explicit_service_completed', true)
            ->assertJsonPath('data.next_action.code', 'write_review')->assertJsonPath('data.review.reservation_id', $reservation['id']);
        $this->checkReviews($headers, $other, $reservationPath, $servicePath, $providerPath);

        $this->get('/api/v1/inquiries?status=completed&page=1&per_page=1', $headers)->assertOk()
            ->assertJsonPath('data.0.id', $id)->assertJsonPath('meta.total', 1);
        $this->get('/api/v1/reservations?status=completed', $headers)->assertOk()->assertJsonPath('data.0.id', $reservation['id']);
        $count = $this->get('/api/v1/notifications/unread-count', $headers)->assertOk()->json('data.unread_count');
        $this->assertGreaterThan(0, $count);
        $this->get('/api/v1/me/dashboard', $headers)->assertOk()->assertJsonPath('data.counts.active', 0)
            ->assertJsonPath('data.counts.completed', 1)->assertJsonPath('data.counts.unread_notifications', $count)
            ->assertJsonPath('data.upcoming_reservations', [])->assertJsonPath('data.recent_inquiries.0.status', 'completed');
        $this->postJson('/api/v1/auth/logout', [], $headers)->assertOk()->assertExactJson(['data' => ['revoked' => true]]);
        Auth::forgetGuards();
        $this->get('/api/v1/me/dashboard', $headers)->assertUnauthorized()->assertJsonPath('error.code', 'unauthenticated');
        $this->assertHoldIntegrity();
    }

    public function test_mobile_errors_keep_authentication_limits_and_storage_failures_distinct(): void
    {
        $path = '/api/v1/me/dashboard';
        $expired = $this->account(RoleName::Client)->createToken('mobile:expired', ['client:read'], now()->subMinute())->plainTextToken;
        $this->get($path, ['Authorization' => 'Bearer '.$expired])->assertUnauthorized()->assertJsonPath('error.code', 'unauthenticated');
        $this->get($path, $this->bearer($this->account(RoleName::Provider)))->assertForbidden()->assertJsonPath('error.code', 'forbidden');

        $headers = $this->bearer($this->account(RoleName::Client));
        for ($i = 1; $i <= 10; $i++) {
            $this->patchJson('/api/v1/me/profile', ['name' => 'Nombre sintético'], $headers)->assertOk();
        }
        $this->patchJson('/api/v1/me/profile', ['name' => 'Otra edición'], $headers)->assertStatus(429)
            ->assertHeader('Retry-After')->assertJsonPath('error.code', 'rate_limited');
        $this->get('/api/v1/me/profile', $headers)->assertOk()->assertJsonPath('data.name', 'Nombre sintético');

        config(['app.debug' => true]);
        DB::statement('DROP TABLE activity_notifications');
        $this->get($path, $headers)->assertStatus(503)->assertJsonPath('error.code', 'temporarily_unavailable')
            ->assertJsonMissingPath('data')->assertDontSee('SQLSTATE')->assertDontSee('activity_notifications');
    }

    public function test_openapi_covers_all_loaded_mobile_methods_and_authentication_without_duplicates(): void
    {
        $spec = json_decode(file_get_contents(base_path('docs/api/openapi.json')), true, flags: JSON_THROW_ON_ERROR);
        $contracts = [];
        foreach ($spec['paths'] as $path => $operations) {
            foreach ($operations as $method => $operation) {
                $contracts[strtoupper($method).' '.$path] = $operation;
            }
        }
        $this->assertCount(49, $contracts);
        $this->assertCount(49, array_unique(array_column($contracts, 'operationId')));

        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/')) {
                continue;
            }
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $key = $method.' '.substr($route->uri(), strlen('api/v1'));
                $this->assertArrayHasKey($key, $contracts);
                $middleware = $route->gatherMiddleware();
                $bearer = in_array('auth:sanctum', $middleware, true);
                $this->assertSame($bearer, in_array(MobileClient::class, $middleware, true), $key);
                $this->assertSame($bearer ? [['bearerAuth' => []]] : [], $contracts[$key]['security'] ?? $spec['security'], $key);
                unset($contracts[$key]);
            }
        }
        $this->assertSame([], $contracts);
    }

    private function registerAndLogin(): array
    {
        Notification::fake();
        $email = 'solicitante-m31@example.test';
        $this->postJson('/api/v1/auth/register', ['email' => $email])->assertAccepted();
        $token = null;
        Notification::assertSentOnDemand(MobileIdentityCode::class, function ($notice, $channels, $recipient) use ($email, &$token) {
            $token = $notice->token;

            return $notice->registration && $recipient->routes['mail'] === $email;
        });
        $password = 'synthetic-m31-password';
        $this->postJson('/api/v1/auth/register/confirm', [
            'email' => $email, 'token' => $token, 'name' => 'Solicitante de prueba',
            'age' => 30, 'gender' => 'Prefiero no decirlo', 'password' => $password, 'password_confirmation' => $password,
        ])->assertAccepted()->assertDontSee($token);
        $user = User::where('email', $email)->sole();
        $this->assertSame(['client'], $user->roles()->pluck('slug')->all());
        $login = $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password, 'device_name' => 'M3.1'])
            ->assertCreated()->assertJsonPath('data.token_type', 'Bearer')->assertJsonPath('data.user.id', $user->id);
        Auth::forgetGuards();

        return [$user, ['Authorization' => 'Bearer '.$login->json('data.access_token')]];
    }

    private function checkPhoto(array $headers): void
    {
        Storage::fake('public');
        $bytes = $this->identityPhotoBytes();
        $this->post('/api/v1/me/photo', ['photo' => UploadedFile::fake()->createWithContent('synthetic.png', $bytes)], $headers + ['Content-Type' => 'multipart/form-data'])
            ->assertOk()->assertJsonPath('data.photo_url', '/api/v1/me/photo');
        $this->get('/api/v1/me/photo', $headers)->assertOk()->assertHeader('Content-Type', 'image/png')
            ->assertHeader('Cache-Control', 'no-store, private')->assertContent($bytes);
    }

    private function checkConversation(array $headers, string $path, ServiceInquiry $inquiry, User $provider): void
    {
        $payload = $this->messageData('¿Qué detalles necesitas del equipo?');
        $reply = $this->postJson($path.'/messages', $payload, $headers)->assertCreated()->assertJsonPath('data.is_mine', true);
        $this->postJson($path.'/messages', $payload, $headers)->assertOk()->assertExactJson($reply->json());
        $this->postJson($path.'/messages', ['body' => 'Otra intención'] + $payload, $headers)->assertConflict()
            ->assertJsonPath('error.code', 'conflict');
        $this->postJson($path.'/messages', $this->messageData('Mi teléfono es 55.0000.0000'), $headers)->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed');
        $received = $this->message($provider, $inquiry, 'Comparte el modelo de tu equipo.');
        $this->assertDatabaseCount('service_inquiry_messages', 2);
        $this->get('/api/v1/conversations', $headers)->assertOk()->assertJsonPath('data.0.unread_messages_count', 1);
        $page = $this->get($path.'/messages', $headers)->assertOk()->assertJsonPath('data.0.is_mine', false)
            ->assertJsonPath('data.0.id', $received->id)->assertJsonPath('meta.unread_messages_count', 1);
        $read = ['read_token' => $page->json('meta.read_token')];
        $marked = $this->postJson($path.'/messages/read', $read, $headers)->assertOk()
            ->assertJsonPath('data.last_read_message_id', $received->id);
        $this->postJson($path.'/messages/read', $read, $headers)->assertOk()->assertExactJson($marked->json());
        $this->get($path.'/journey', $headers)->assertOk()->assertJsonPath('data.conversation.unread_count', 0);
    }

    private function checkNotices(array $headers, int $inquiry): void
    {
        $before = $this->get('/api/v1/notifications/unread-count', $headers)->assertOk()->json('data.unread_count');
        $this->assertGreaterThan(0, $before);
        $rows = $this->get('/api/v1/notifications?per_page=12', $headers)->assertOk()->json('data');
        $notice = collect($rows)->firstWhere('event', 'accepted');
        $this->assertSame($inquiry, $notice['resource']['id']);
        $read = '/api/v1/notifications/'.$notice['source_type'].'/'.$notice['source_id'].'/read';
        $first = $this->postJson($read, [], $headers)->assertOk();
        $this->assertNotNull($first->json('data.read_at'));
        $this->postJson($read, [], $headers)->assertOk()->assertExactJson($first->json());
        $this->get('/api/v1/notifications/unread-count', $headers)->assertOk()->assertJsonPath('data.unread_count', $before - 1);
        $this->get('/api/v1/me/dashboard', $headers)->assertOk()->assertJsonPath('data.counts.unread_notifications', $before - 1);
    }

    private function confirmBooking(array $headers, string $path, User $provider): string
    {
        $this->get($path.'/quotes', $headers)->assertOk()->assertJsonPath('meta.total', 0);
        $issue = ['idempotency_key' => (string) Str::uuid()];
        $quote = $this->postJson($path.'/quotes', $issue, $headers)->assertOk()->assertJsonPath('data.acceptance.state', 'pending')->json('data.id');
        $this->postJson($path.'/quotes', $issue, $headers)->assertOk()->assertJsonPath('data.id', $quote);
        $this->get($path.'/quotes?per_page=1', $headers)->assertOk()->assertJsonPath('meta.total', 1);
        $this->get('/api/v1/quotes/'.$quote, $headers)->assertOk()->assertJsonPath('data.amount.total_cents', 100000);
        $this->postJson('/api/v1/quotes/'.$quote.'/accept', ['acknowledge' => true], $headers)->assertOk()
            ->assertJsonPath('data.acceptance.state', 'accepted');
        $this->get('/api/v1/quotes/'.$quote.'/acceptance', $headers)->assertOk()->assertJsonPath('data.state', 'accepted');

        $options = $this->get($path.'/reservation-options', $headers)->assertOk()
            ->assertJsonPath('data.commercial.accepted_quotes.0.id', $quote)->assertJsonPath('data.commercial.hold_seconds', 900);
        $proposal = $this->reservationData(['quote_id' => $quote, 'availability_revision' => $options->json('data.availability_revision')]);
        $reservation = $this->postJson($path.'/reservations', $proposal, $headers)->assertCreated()
            ->assertJsonPath('data.status', 'requested')->assertJsonPath('data.version', 1)->json('data.id');
        $this->postJson($path.'/reservations', $proposal, $headers)->assertOk()->assertJsonPath('data.id', $reservation);
        $this->assertDatabaseCount('service_reservations', 1);
        $detail = '/api/v1/reservations/'.$reservation;
        $commit = $this->holdInput();
        $this->patchJson($detail.'/commit', $commit, $headers)->assertConflict();
        app(ReservationWorkflow::class)->decide($provider, $reservation, 'confirm', $this->holdInput());
        $held = $this->get($detail, $headers)->assertOk()->assertJsonPath('data.status', 'held')
            ->assertJsonPath('data.hold.remaining_seconds', 900)->assertJsonPath('data.version', 1);
        $this->assertSame(900, CarbonImmutable::parse($held->json('data.hold.expires_at'))->getTimestamp()
            - CarbonImmutable::parse($held->json('data.hold.accepted_at'))->getTimestamp());
        $this->get($path.'/journey', $headers)->assertOk()->assertJsonPath('data.reservation.status', 'held')
            ->assertJsonPath('data.reservation.hold.expires_at', $held->json('data.hold.expires_at'))
            ->assertJsonPath('data.confirmation_readiness.state', 'ready');
        $this->get($detail.'/checkout-context', $headers)->assertOk()->assertJsonPath('data.future_payment.enabled', false)
            ->assertJsonPath('data.confirmation_readiness.can_offer_mobile_confirmation', true);
        $this->get('/api/v1/me/dashboard', $headers)->assertOk()->assertJsonPath('data.counts.held', 1)->assertJsonPath('data.counts.requested', 0);
        $this->patchJson($detail.'/commit', $commit, $headers)->assertOk()->assertJsonPath('data.status', 'confirmed')->assertJsonPath('data.version', 2);
        $this->patchJson($detail.'/commit', $commit, $headers)->assertOk()->assertJsonPath('data.version', 2);
        $this->patchJson($detail.'/commit', $this->holdInput(), $headers)->assertConflict();
        $this->get($path.'/journey', $headers)->assertOk()->assertJsonPath('data.reservation.id', $reservation)
            ->assertJsonPath('data.reservation.status', 'confirmed')->assertJsonPath('data.payments.enabled', false);

        return $detail;
    }

    private function assertForeignAccessIsHidden(string $inquiry, string $reservation, array $headers): void
    {
        $this->get($inquiry, $headers)->assertNotFound()->assertJsonPath('error.code', 'not_found');
        $this->get($inquiry.'/journey', $headers)->assertNotFound();
        $this->get($reservation, $headers)->assertNotFound();
        $this->get('/api/v1/me/dashboard', $headers)->assertOk()->assertJsonPath('data.recent_inquiries', [])
            ->assertJsonPath('data.upcoming_reservations', [])->assertJsonPath('data.counts.unread_notifications', 0);
    }

    private function checkReviews(array $headers, User $other, string $reservation, string $service, string $provider): void
    {
        $body = ['rating' => 5, 'comment' => 'El diagnóstico fue claro.'];
        $review = $this->postJson($reservation.'/review', $body, $headers)->assertCreated()->assertJsonPath('data.status', 'published')->json('data.id');
        $this->postJson($reservation.'/review', $body, $headers)->assertOk()->assertJsonPath('data.id', $review);
        $this->get('/api/v1/reviews?page=1', $headers)->assertOk()->assertJsonPath('data.0.id', $review);
        $this->get('/api/v1/reviews/'.$review, $this->bearer($other))->assertNotFound();
        Auth::forgetGuards();
        $this->get('/api/v1/reviews/'.$review, $headers)->assertOk()->assertJsonPath('data.rating', 5);
        foreach ([$service, $provider] as $path) {
            $this->get($path.'/reviews', $headers)->assertOk()->assertJsonPath('meta.total', 1)
                ->assertJsonPath('data.0.rating', 5)->assertJsonMissingPath('data.0.author')->assertJsonMissingPath('data.0.client_user_id');
        }
        $withdraw = '/api/v1/reviews/'.$review.'/withdraw';
        $this->patch($withdraw, [], $headers)->assertOk()->assertJsonPath('data.status', 'withdrawn');
        $this->patch($withdraw, [], $headers)->assertOk()->assertJsonPath('data.id', $review);
        $this->get('/api/v1/reviews/'.$review, $headers)->assertOk()->assertJsonPath('data.status', 'withdrawn');
        $this->get($service.'/reviews')->assertOk()->assertJsonPath('meta.total', 0);
        $this->get($provider.'/reviews')->assertOk()->assertJsonPath('meta.total', 0);
        $this->assertDatabaseCount('service_reviews', 1);
    }
}
