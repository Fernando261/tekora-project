<?php

namespace Tests\Feature;

use App\Models\Emotion;
use App\Models\Profesionista;
use App\Models\User;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;
use Google\Client as GoogleClient;
use Google\Service\Calendar;
use Google\Service\Calendar\Event;
use Google\Service\Calendar\FreeBusyRequest;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use phpseclib3\Crypt\RSA;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

class ComposerDependencyCompatibilityTest extends TestCase
{
    private function http(array $responses, array &$history): HttpClient
    {
        $mock = new MockHandler($responses);
        // Registrar al llegar al transporte, después de aplicar el middleware OAuth.
        $stack = HandlerStack::create(function (RequestInterface $request, array $options) use ($mock, &$history) {
            $history[] = ['request' => $request];

            return $mock($request, $options);
        });

        return new HttpClient(['handler' => $stack]);
    }

    private function socialiteHttp(array $responses, array &$history): void
    {
        $http = $this->http($responses, $history);
        // Construir el proveedor real al recibir el callback para que use su Request/sesión.
        Socialite::extend('google', function ($app) use ($http) {
            return (new GoogleProvider($app['request'], 'fake-client-id', 'fake-client-secret',
                'http://localhost/google/callback'))->setHttpClient($http);
        });
    }

    private function professional(): Profesionista
    {
        config(['services.google.client_id' => 'fake-client-id', 'services.google.client_secret' => 'fake-client-secret',
            'services.google.redirect' => 'http://localhost/google/callback']);

        return Profesionista::create(['nombre' => 'Compatibilidad', 'email' => 'google-compat@example.test',
            'password' => 'fake-password', 'cedula' => 'COMPAT-GOOGLE',
            'google_access_token' => 'fake-previous-access', 'google_refresh_token' => 'fake-preserved-refresh']);
    }

    public function test_google_sdk_refresh_freebusy_and_event_use_the_updated_transport_without_network(): void
    {
        $history = [];
        $client = new GoogleClient;
        $client->setClientId('fake-client-id');
        $client->setClientSecret('fake-client-secret');
        $client->setScopes([Calendar::CALENDAR]);
        $client->setHttpClient($this->http([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'access_token' => 'fake-refreshed-access', 'expires_in' => 3600, 'token_type' => 'Bearer'])),
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'kind' => 'calendar#freeBusy', 'calendars' => ['primary' => ['busy' => [
                    ['start' => '2030-01-07T10:00:00-06:00', 'end' => '2030-01-07T10:50:00-06:00']]]]])),
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'kind' => 'calendar#event', 'id' => 'fake-created-event', 'status' => 'confirmed'])),
        ], $history));
        $token = $client->fetchAccessTokenWithRefreshToken('fake-preserved-refresh');
        $this->assertSame('fake-refreshed-access', $token['access_token']);
        $this->assertSame('fake-preserved-refresh', $client->getAccessToken()['refresh_token']);
        $this->assertFalse($client->isAccessTokenExpired());
        $calendar = new Calendar($client);
        $freeBusy = $calendar->freebusy->query(new FreeBusyRequest([
            'timeMin' => '2030-01-07T00:00:00-06:00', 'timeMax' => '2030-01-08T00:00:00-06:00',
            'timeZone' => 'America/Mexico_City', 'items' => [['id' => 'primary']],
        ]));
        $this->assertSame('2030-01-07T10:00:00-06:00', $freeBusy->getCalendars()['primary']->getBusy()[0]->getStart());
        $event = $calendar->events->insert('primary', new Event([
            'summary' => 'Cita de prueba', 'start' => ['dateTime' => '2030-01-07T11:00:00-06:00'],
            'end' => ['dateTime' => '2030-01-07T11:50:00-06:00'],
        ]));
        $this->assertSame('fake-created-event', $event->getId());
        $this->assertCount(3, $history);
        $this->assertStringContainsString('grant_type=refresh_token', (string) $history[0]['request']->getBody());
        $this->assertStringContainsString('refresh_token=fake-preserved-refresh', (string) $history[0]['request']->getBody());
        foreach (array_slice($history, 1) as $entry) {
            $this->assertSame('Bearer fake-refreshed-access', $entry['request']->getHeaderLine('Authorization'));
            $this->assertSame('https', $entry['request']->getUri()->getScheme());
        }
        $this->assertStringContainsString('/calendar/v3/freeBusy', (string) $history[1]['request']->getUri());
        $this->assertStringContainsString('/calendars/primary/events', (string) $history[2]['request']->getUri());
    }

    public function test_real_socialite_provider_rejects_wrong_state_before_any_http_request(): void
    {
        $professional = $this->professional();
        $history = [];
        $this->socialiteHttp([], $history);
        $this->actingAs($professional, 'profesionista')->withSession(['state' => 'expected-state'])
            ->get('/google/callback?state=wrong-state&code=fake-code')
            ->assertRedirect('/dashboard-profesionista')->assertSessionHas('error');
        $this->assertCount(0, $history);
        $this->assertSame('fake-previous-access', $professional->fresh()->google_access_token);
        $this->assertSame('fake-preserved-refresh', $professional->fresh()->google_refresh_token);
    }

    public function test_real_socialite_callback_preserves_refresh_token_when_provider_omits_it(): void
    {
        $professional = $this->professional();
        $history = [];
        $this->socialiteHttp([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'access_token' => 'fake-new-access', 'token_type' => 'Bearer', 'expires_in' => 3600])),
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'sub' => 'fake-google-id', 'name' => 'Prueba', 'email' => $professional->email, 'email_verified' => true])),
        ], $history);
        $this->actingAs($professional, 'profesionista')->withSession(['state' => 'expected-state'])
            ->get('/google/callback?state=expected-state&code=fake-code')
            ->assertRedirect('/dashboard-profesionista')->assertSessionHas('success');
        $this->assertSame('fake-new-access', $professional->fresh()->google_access_token);
        $this->assertSame('fake-preserved-refresh', $professional->fresh()->google_refresh_token);
        $this->assertCount(2, $history);
        $this->assertSame('Bearer fake-new-access', $history[1]['request']->getHeaderLine('Authorization'));
    }

    public function test_oauth_transport_error_does_not_log_response_body_or_replace_credentials(): void
    {
        $professional = $this->professional();
        $history = [];
        $this->socialiteHttp([
            new Response(400, ['Content-Type' => 'application/json'], '{"error":"fake-sensitive-provider-token"}'),
        ], $history);
        Log::spy();
        $response = $this->actingAs($professional, 'profesionista')->withSession(['state' => 'expected-state'])
            ->get('/google/callback?state=expected-state&code=fake-code')
            ->assertRedirect('/dashboard-profesionista')->assertSessionHas('error');
        $this->assertStringNotContainsString('fake-sensitive-provider-token', $response->getContent());
        Log::shouldHaveReceived('warning')->once()->with('No se pudo conectar Google Calendar', [
            'profesionista_id' => $professional->id, 'exception' => \GuzzleHttp\Exception\ClientException::class, 'code' => 400,
        ]);
        $this->assertSame('fake-previous-access', $professional->fresh()->google_access_token);
        $this->assertSame('fake-preserved-refresh', $professional->fresh()->google_refresh_token);
    }

    public function test_phpseclib_and_pinned_jwt_verify_rs256_and_reject_a_modified_signature(): void
    {
        $private = RSA::createKey(2048);
        $public = $private->getPublicKey()->toString('PKCS8');
        $token = JWT::encode(['sub' => 'fake-subject', 'exp' => time() + 60], $private->toString('PKCS8'), 'RS256');
        $this->assertSame('fake-subject', JWT::decode($token, new Key($public, 'RS256'))->sub);
        $parts = explode('.', $token);
        $signature = JWT::urlsafeB64Decode($parts[2]);
        $signature[0] = chr(ord($signature[0]) ^ 1);
        $parts[2] = JWT::urlsafeB64Encode($signature);
        $this->expectException(SignatureInvalidException::class);
        JWT::decode(implode('.', $parts), new Key($public, 'RS256'));
    }

    public function test_emotion_pdf_route_still_generates_a_download_with_the_updated_dompdf(): void
    {
        Emotion::create(['name' => 'Calma de prueba', 'slug' => 'compat-pdf', 'image_path' => 'missing-test-image.png']);
        $response = $this->actingAs(User::factory()->create(), 'web')->get('/pdf/emociones/tarjetas');
        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('attachment;', $response->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF-', $response->getContent());
    }
}
