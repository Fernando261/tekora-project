<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\ServiceInquiry;
use App\Models\ServiceReservation;
use App\Models\ServiceReview;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\ReservationFixtures;
use Tests\Support\WithoutLegacySchema;
use Tests\TestCase;

#[Group('legacy-decoupling-w62a')]
class NativeLegacyIndependenceTest extends TestCase
{
    use ReservationFixtures, WithoutLegacySchema;

    protected function setUp(): void
    {
        parent::setUp();
        $old = storage_path();
        $temporary = sys_get_temp_dir().'/tekora-w62a-native-'.bin2hex(random_bytes(8));
        app()->useStoragePath($temporary);
        $this->beforeApplicationDestroyed(function () use ($old, $temporary) {
            app()->useStoragePath($old);
            (new Filesystem)->deleteDirectory($temporary);
        });
        Storage::fake('public');
        $this->removeLegacySchema();
        config(['auth.guards.profesionista' => null, 'auth.providers.profesionistas' => null, 'services.google' => null]);
        Auth::forgetGuards();
        Http::preventStrayRequests();
        Socialite::shouldReceive('driver')->never();
        DB::enableQueryLog();
        DB::flushQueryLog();
    }

    private function assertNoLegacyDomainQueries(): void
    {
        $pattern = '/\b(?:from|join|into|update)\s+["`\[]?(?:posts|comments|likes|emotions|reminders|citas|profesionistas|profesionista_bloqueos|profesionista_disponibilidades)\b/i';
        $queries = array_column(DB::getQueryLog(), 'query');
        $this->assertNotEmpty($queries);
        $this->assertSame([], array_values(preg_grep($pattern, $queries)));
    }

    public function test_native_workflows_and_account_surfaces_operate_without_the_old_schema_guard_or_calendar(): void
    {
        $this->travelTo(now('UTC')->setDate(2030, 1, 1)->setTime(12, 0));
        $client = $this->account(RoleName::Client);
        $provider = $this->account(RoleName::Client, RoleName::Provider);
        $service = $this->service($provider);
        $this->publishSchedule($provider);
        $this->actingAs($client)->post(route('inquiries.store', $service), ['message' => 'Necesito diagnóstico de mi computadora.'])->assertStatus(303);
        $inquiry = ServiceInquiry::sole();
        $payload = ['body' => 'Mensaje sintético del cliente', 'idempotency_key' => (string) Str::uuid()];
        $this->post(route('inquiries.messages.store', $inquiry), $payload)->assertStatus(303);
        $this->post(route('inquiries.messages.store', $inquiry), $payload)->assertStatus(303);
        $this->assertSame(1, $inquiry->messages()->count());
        $this->actingAs($provider)->get(route('conversations.index'))->assertOk();
        $this->patch(route('inquiries.accept', $inquiry))->assertStatus(303);
        $this->actingAs($client)->post(route('reservations.store', $inquiry), $this->reservationData())->assertStatus(303);
        $reservation = ServiceReservation::sole();
        $this->actingAs($provider)->patch(route('reservations.confirm', $reservation), ['version' => 1])->assertStatus(303);
        $this->travelTo(Carbon::parse($reservation->ends_at_utc, 'UTC')->addMinute());
        $this->patch(route('reservations.complete', $reservation), ['version' => 2])->assertStatus(303);
        $this->actingAs($client)->post(route('reviews.store', $reservation), ['rating' => 5, 'comment' => 'Servicio sintético satisfactorio.'])->assertStatus(303);
        $this->assertSame(1, ServiceReview::count());
        $this->assertSame(3, $reservation->events()->count());

        foreach ([$client, $provider] as $actor) {
            $this->actingAs($actor);
            foreach (['account.index', 'account.client', 'inquiries.index', 'conversations.index', 'reservations.index', 'reviews.index', 'notifications.index', 'perfil'] as $name) {
                $this->get(route($name))->assertOk();
            }
        }
        $this->actingAs($provider);
        foreach (['provider.dashboard', 'account.provider', 'provider-services.index', 'availability.edit', 'inquiries.received'] as $name) {
            $this->get(route($name))->assertOk();
        }
        $this->get(route('catalog.show', $service))->assertOk();
        $this->get(route('public-providers.show', $provider->providerProfile))->assertOk();
        $this->assertNoLegacyDomainQueries();
    }

    public function test_native_authorization_still_denies_nonparticipants_after_decoupling(): void
    {
        [$client, $provider, $inquiry] = $this->reservationContext();
        $reservation = $this->proposeReservation($inquiry);
        $outsider = $this->account(RoleName::Client, RoleName::Provider);
        $this->actingAs($outsider)->get(route('inquiries.show', $inquiry))->assertForbidden();
        $this->get(route('reservations.show', $reservation))->assertForbidden();
        $this->postJson(route('inquiries.messages.store', $inquiry), ['body' => 'No autorizado', 'idempotency_key' => (string) Str::uuid()])->assertForbidden();
        $this->patchJson(route('reservations.confirm', $reservation), ['version' => 1])->assertForbidden();
        $this->assertSame($client->id, $reservation->fresh()->client_user_id);
        $this->assertSame(1, $reservation->fresh()->version);
        $this->assertNoLegacyDomainQueries();
    }

    public function test_central_registration_login_profile_and_logout_need_no_professional_identity(): void
    {
        $this->post('/registro', ['name' => 'Cuenta sintética', 'email' => 'synthetic-w62a@example.test', 'age' => 30,
            'gender' => 'Otro', 'password' => 'synthetic-password', 'password_confirmation' => 'synthetic-password', 'intent' => 'both'])
            ->assertRedirect('/login')->assertSessionHasNoErrors();
        $user = User::sole();
        $this->assertTrue($user->hasRole(RoleName::Client));
        $this->assertTrue($user->hasRole(RoleName::Provider));
        $this->post('/login', ['email' => $user->email, 'password' => 'synthetic-password'])->assertRedirect(route('account.provider'));
        $this->assertAuthenticatedAs($user, 'web');
        $this->get('/perfil')->assertOk();
        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest('web');
        $this->assertNoLegacyDomainQueries();
    }

    private function bearer(string $token): array
    {
        Auth::forgetGuards();

        return ['Authorization' => 'Bearer '.$token];
    }

    public function test_mobile_device_rotation_revocation_and_native_reads_need_no_legacy_modules(): void
    {
        [$client, , $inquiry] = $this->reservationContext();
        $reservation = $this->proposeReservation($inquiry);
        $login = fn ($device) => $this->postJson('/api/v1/auth/login', ['email' => $client->email, 'password' => 'password', 'device_name' => $device])
            ->assertCreated()->json('data.access_token');
        $old = $login('Synthetic phone');
        $tablet = $login('Synthetic tablet');
        $phone = $login('Synthetic phone');
        $this->getJson('/api/v1/me', $this->bearer($old))->assertUnauthorized();
        foreach ([$phone, $tablet] as $token) {
            foreach (['me', 'inquiries', 'conversations', 'reservations', 'services', 'providers'] as $path) {
                $this->getJson('/api/v1/'.$path, $this->bearer($token))->assertOk();
            }
            $this->getJson('/api/v1/reservations/'.$reservation->id, $this->bearer($token))->assertOk();
        }
        $this->postJson('/api/v1/auth/logout', [], $this->bearer($phone))->assertOk();
        $this->getJson('/api/v1/me', $this->bearer($phone))->assertUnauthorized();
        $this->getJson('/api/v1/me', $this->bearer($tablet))->assertOk();
        $this->assertSame(1, $client->tokens()->count());
        $this->assertNoLegacyDomainQueries();
    }

    public function test_native_account_layout_loads_its_own_base_and_preserves_native_assets_and_logout(): void
    {
        $actor = $this->account(RoleName::Client, RoleName::Provider);
        $this->actingAs($actor);
        foreach (['account.index', 'account.client', 'provider.dashboard', 'perfil', 'inquiries.index', 'reservations.index'] as $name) {
            $response = $this->get(route($name))->assertOk();
            $response->assertSee('css/tekora/account-base.css', false)->assertDontSee('css/home.css', false)
                ->assertSee('css/account.css', false)->assertSee('action="'.route('logout').'"', false)
                ->assertSee('name="_token"', false);
        }
        $this->assertNoLegacyDomainQueries();
    }
}
