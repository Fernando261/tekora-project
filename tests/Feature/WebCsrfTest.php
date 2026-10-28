<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Tests\Support\EnforcedCsrf;
use Tests\TestCase;

class WebCsrfTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->bind(ValidateCsrfToken::class, EnforcedCsrf::class);
    }

    private function provider(): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::whereIn('slug', [RoleName::Client->value, RoleName::Provider->value])->pluck('id'));

        return $user;
    }

    public function test_registration_and_login_require_csrf_without_changing_the_environment(): void
    {
        $this->post('/registro', ['email' => 'client@example.test'])->assertStatus(419)->assertSee('Tu sesión caducó');
        $this->post('/login', ['email' => 'client@example.test', 'password' => 'test-password'])->assertStatus(419);
        $this->assertDatabaseCount('users', 0);
        $this->assertSame('testing', app()->environment());
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
    }

    public function test_activation_and_profile_creation_reject_missing_or_wrong_tokens(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'web')->post('/cuenta/roles', ['role' => 'provider'])->assertStatus(419);
        $this->assertDatabaseCount('role_user', 0);
        $this->actingAs($this->provider(), 'web')->withSession(['_token' => 'valid-test-token'])
            ->post('/perfil-profesional', ['display_name' => 'No guardar', '_token' => 'wrong-token'])->assertStatus(419);
        $this->assertDatabaseCount('provider_profiles', 0);
    }

    public function test_valid_tokens_allow_profile_changes_and_stale_tokens_are_rejected(): void
    {
        $user = $this->provider();
        $this->actingAs($user, 'web')->withSession(['_token' => 'valid-test-token'])
            ->post('/perfil-profesional', ['display_name' => 'Perfil', '_token' => 'valid-test-token'])->assertRedirect('/cuenta/prestador');
        $profile = $user->providerProfile()->firstOrFail();
        $this->put('/perfiles-profesionales/'.$profile->id, ['display_name' => 'No cambiar'])->assertStatus(419);
        $this->put('/perfiles-profesionales/'.$profile->id, ['display_name' => 'Actualizado', '_token' => 'valid-test-token'])
            ->assertRedirect('/cuenta/prestador');
        $this->assertSame('Actualizado', $profile->fresh()->display_name);
    }

    public function test_a_valid_token_does_not_bypass_role_authorization(): void
    {
        $user = $this->provider();
        $this->actingAs($user, 'web')->withSession(['_token' => 'valid-test-token'])
            ->put('/administracion/usuarios/'.$user->id.'/roles', ['roles' => ['admin'], '_token' => 'valid-test-token'])
            ->assertForbidden();
        $this->post('/cuenta/roles', ['role' => 'admin', '_token' => 'valid-test-token'])->assertSessionHasErrors('role');
        $this->assertFalse($user->hasRole(RoleName::Admin));
    }

    public function test_generated_forms_include_a_csrf_token(): void
    {
        foreach (['/login', '/registro'] as $path) {
            $this->get($path)->assertOk()->assertSee('name="_token"', false);
        }
        $this->actingAs($this->provider(), 'web')->get('/cuenta/prestador')->assertOk()->assertSee('name="_token"', false);
    }

    public function test_registration_login_and_activation_work_with_real_csrf_and_a_rotated_session_token(): void
    {
        $this->withSession(['_token' => 'before-login-token'])->post('/registro', [
            '_token' => 'before-login-token', 'name' => 'Cuenta central', 'age' => 30, 'gender' => 'Prefiero no decirlo',
            'email' => 'central@example.test', 'password' => 'test-password', 'password_confirmation' => 'test-password',
            'intent' => 'client',
        ])->assertRedirect('/login');
        $this->post('/login', ['_token' => 'before-login-token', 'email' => 'central@example.test', 'password' => 'test-password'])
            ->assertRedirect('/cuenta');
        $newToken = session()->token();
        $this->assertNotSame('before-login-token', $newToken);
        $this->post('/cuenta/roles', ['_token' => 'before-login-token', 'role' => 'provider'])->assertStatus(419);
        $this->post('/cuenta/roles', ['_token' => $newToken, 'role' => 'provider'])->assertRedirect('/cuenta/prestador');
        $this->post('/perfil-profesional', ['_token' => $newToken, 'display_name' => 'Perfil central'])
            ->assertRedirect('/cuenta/prestador');
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('role_user', 2);
        $this->assertDatabaseCount('provider_profiles', 1);
        $this->assertDatabaseCount('profesionistas', 0);
    }
}
