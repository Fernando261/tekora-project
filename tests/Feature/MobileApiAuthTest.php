<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Tests\Support\CatalogFixtures;
use Tests\TestCase;

class MobileApiAuthTest extends TestCase
{
    use CatalogFixtures;

    private function login(User $user, string $device = 'Expo Android')
    {
        return $this->postJson('/api/v1/auth/login', ['email' => $user->email,
            'password' => 'password', 'device_name' => $device]);
    }

    private function bearer(string $token): array
    {
        // Emular peticiones HTTP independientes; el guard RequestGuard se memoriza en pruebas.
        Auth::forgetGuards();

        return ['Authorization' => 'Bearer '.$token];
    }

    public function test_login_creates_a_hashed_expiring_client_token_without_starting_web_sessions(): void
    {
        $user = $this->account(RoleName::Client, RoleName::Provider, RoleName::Admin);
        $response = $this->login($user)->assertCreated()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.token_type', 'Bearer')->assertJsonPath('data.user.experience', 'client');
        $plain = $response->json('data.access_token');
        $token = $user->tokens()->sole();
        $this->assertNotSame($plain, $token->token);
        $this->assertSame(hash('sha256', explode('|', $plain, 2)[1]), $token->token);
        $this->assertSame(['client:read'], $token->abilities);
        $this->assertEqualsWithDelta(now()->addDays(30)->timestamp, $token->expires_at->timestamp, 2);
        $this->assertGuest('web');
        $this->assertNull(config('auth.guards.profesionista'));
        $this->get('/api/v1/me', $this->bearer($plain))->assertOk()->assertExactJson(['data' => [
            'id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'experience' => 'client',
            'permissions' => ['client.appointments.view', 'client.inquiries.manage'],
        ]]);
    }

    public function test_wrong_unknown_and_ineligible_accounts_have_the_same_login_error(): void
    {
        $client = $this->account(RoleName::Client);
        $provider = $this->account(RoleName::Provider);
        $admin = $this->account(RoleName::Admin);
        $unassigned = $this->account();
        $errors = [];
        foreach ([[$client->email, 'wrong'], ['unknown@example.test', 'password'],
            [$provider->email, 'password'], [$admin->email, 'password'], [$unassigned->email, 'password']] as [$email, $password]) {
            $errors[] = $this->postJson('/api/v1/auth/login', compact('email', 'password') + ['device_name' => 'Expo'])
                ->assertUnauthorized()->json();
        }
        foreach ($errors as $error) {
            $this->assertSame($errors[0], $error);
        }
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_login_validation_rejects_malformed_fields_and_privilege_injection_as_json(): void
    {
        foreach ([['email' => [], 'password' => [], 'device_name' => []],
            ['email' => 'client@example.test', 'password' => 'password', 'device_name' => '<script>x</script>'],
            ['email' => 'client@example.test', 'password' => 'password', 'device_name' => 'Expo', 'abilities' => ['*']]] as $input) {
            $this->post('/api/v1/auth/login', $input, ['Accept' => 'text/html'])->assertUnprocessable()
                ->assertJsonPath('error.code', 'validation_failed')->assertJsonStructure(['error' => ['fields']]);
        }
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_identity_and_ip_login_limits_apply_and_recover(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => $i % 2 ? 'LIMIT@example.test' : 'limit@example.test',
                'password' => 'wrong', 'device_name' => 'Expo'])->assertUnauthorized();
        }
        $this->postJson('/api/v1/auth/login', ['email' => 'limit@example.test', 'password' => 'wrong', 'device_name' => 'Expo'])
            ->assertStatus(429)->assertHeader('Retry-After')->assertJsonPath('error.code', 'rate_limited');
        $this->travel(61)->seconds();
        for ($i = 0; $i < 20; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => "other{$i}@example.test", 'password' => 'wrong', 'device_name' => 'Expo'])
                ->assertUnauthorized();
        }
        $this->postJson('/api/v1/auth/login', ['email' => 'another@example.test', 'password' => 'wrong', 'device_name' => 'Expo'])
            ->assertStatus(429);
    }

    public function test_web_session_cannot_replace_a_bearer_token_and_still_works_on_web(): void
    {
        $user = $this->account(RoleName::Client, RoleName::Provider);
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/cuenta/prestador');
        $this->get('/cuenta')->assertOk();
        $this->get('/api/v1/me')->assertUnauthorized()->assertJsonPath('error.code', 'unauthenticated');
        $this->post('/api/v1/auth/logout')->assertUnauthorized();
        $this->get('/cuenta/prestador/dashboard')->assertOk();
        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_dual_account_bearer_does_not_grant_access_to_provider_admin_or_client_web_routes(): void
    {
        $user = $this->account(RoleName::Client, RoleName::Provider, RoleName::Admin);
        $plain = $this->login($user)->json('data.access_token');
        foreach (['/cuenta', '/cuenta/prestador/dashboard', '/administracion/roles', '/cuenta/cliente'] as $path) {
            $this->get($path, $this->bearer($plain))->assertRedirect();
            $this->assertGuest('web');
        }
        foreach (['/api/v1/provider/dashboard', '/api/v1/admin', '/api/v1/users/'.$user->id] as $path) {
            $this->get($path, $this->bearer($plain))->assertNotFound()->assertJsonPath('error.code', 'not_found');
        }
    }

    public function test_permissions_are_rechecked_and_token_abilities_alone_are_insufficient(): void
    {
        $user = $this->account(RoleName::Client, RoleName::Provider);
        $plain = $this->login($user)->json('data.access_token');
        $user->load('roles');
        $user->roles()->detach(\App\Models\Role::where('slug', 'client')->value('id'));
        $this->get('/api/v1/me', $this->bearer($plain))->assertForbidden();
        $this->post('/api/v1/auth/logout', [], $this->bearer($plain))->assertForbidden();
        $this->login($user)->assertUnauthorized();
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_missing_wrong_and_expired_tokens_are_rejected(): void
    {
        $user = $this->account(RoleName::Client);
        $plain = $this->login($user)->json('data.access_token');
        $this->get('/api/v1/me?access_token='.urlencode($plain))->assertUnauthorized();
        $this->get('/api/v1/me', $this->bearer('bad'))->assertUnauthorized();
        $this->travel(31)->days();
        $this->get('/api/v1/me', $this->bearer($plain))->assertUnauthorized();
        $this->post('/api/v1/auth/logout', [], $this->bearer($plain))->assertUnauthorized();
    }

    public function test_non_mobile_or_wrong_ability_tokens_are_rejected(): void
    {
        $user = $this->account(RoleName::Client);
        foreach ([['other', ['client:read']], ['mobile:Expo', ['provider:manage']]] as [$name, $abilities]) {
            $plain = $user->createToken($name, $abilities, now()->addDay())->plainTextToken;
            $this->get('/api/v1/me', $this->bearer($plain))->assertForbidden();
        }
    }

    public function test_logout_revokes_only_current_token_and_preserves_other_users_devices_and_web_session(): void
    {
        $user = $this->account(RoleName::Client);
        $other = $this->account(RoleName::Client);
        $first = $this->login($user, 'Android')->json('data.access_token');
        $second = $this->login($user, 'iOS')->json('data.access_token');
        $third = $this->login($other)->json('data.access_token');
        $this->actingAs($user, 'web');
        $this->post('/api/v1/auth/logout', [], ['Authorization' => 'Bearer '.$first])->assertOk()->assertJsonPath('data.revoked', true);
        $this->get('/cuenta')->assertOk();
        $this->get('/api/v1/me', $this->bearer($first))->assertUnauthorized();
        $this->get('/api/v1/me', $this->bearer($second))->assertOk()->assertJsonPath('data.id', $user->id);
        $this->get('/api/v1/me', $this->bearer($third))->assertOk()->assertJsonPath('data.id', $other->id);
        $this->assertDatabaseCount('personal_access_tokens', 2);
    }

    public function test_device_rotation_and_user_limit_preserve_other_token_namespaces(): void
    {
        $user = $this->account(RoleName::Client);
        $other = $user->createToken('integration', ['other'])->accessToken->id;
        $first = $this->login($user, 'same')->json('data.access_token');
        $second = $this->login($user, 'same')->json('data.access_token');
        $this->get('/api/v1/me', $this->bearer($first))->assertUnauthorized();
        $this->get('/api/v1/me', $this->bearer($second))->assertOk();
        for ($i = 0; $i < 6; $i++) {
            $this->travel(61)->seconds();
            $last = $this->login($user, 'device '.$i)->assertCreated()->json('data.access_token');
        }
        $this->assertSame(5, $user->tokens()->where('name', 'like', 'mobile:%')->count());
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $other]);
        $this->get('/api/v1/me', $this->bearer($second))->assertUnauthorized();
        $this->get('/api/v1/me', $this->bearer($last))->assertOk();
    }

    public function test_token_insertion_failure_rolls_back_device_rotation_and_returns_safe_json(): void
    {
        $user = $this->account(RoleName::Client);
        $plain = $this->login($user, 'same')->json('data.access_token');
        // Fallo real de SQLite después de borrar el token anterior dentro de la transacción.
        \Illuminate\Support\Facades\DB::unprepared("CREATE TRIGGER reject_mobile_token BEFORE INSERT ON personal_access_tokens BEGIN SELECT RAISE(ABORT, 'PRIVATE SQL token failure'); END");
        $response = $this->login($user, 'same')->assertStatus(503)->assertJsonPath('error.code', 'temporarily_unavailable');
        $this->assertStringNotContainsString('PRIVATE', $response->getContent());
        $this->assertStringNotContainsString($plain, $response->getContent());
        $this->assertSame(1, $user->tokens()->count());
        $this->get('/api/v1/me', $this->bearer($plain))->assertOk();
    }

    public function test_sanctum_migration_is_additive_and_reversible_in_memory(): void
    {
        $user = $this->account(RoleName::Client);
        $before = $user->fresh()->getAttributes();
        $migration = require database_path('migrations/2026_11_04_000000_create_personal_access_tokens_table.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('personal_access_tokens'));
        $this->assertSame($before, $user->fresh()->getAttributes());
        $migration->up();
        $this->assertTrue(Schema::hasColumns('personal_access_tokens', ['token', 'tokenable_id', 'abilities', 'expires_at']));
        $this->assertSame($before, $user->fresh()->getAttributes());
    }
}
