<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\Profesionista;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class WebAccountsTest extends TestCase
{
    private function account(RoleName ...$roles): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::whereIn('slug', array_map(fn ($role) => $role->value, $roles))->pluck('id'));

        return $user;
    }

    private function registration(string $intent = 'client'): array
    {
        return ['name' => 'Cuenta de prueba', 'age' => 28, 'gender' => 'Prefiero no decirlo',
            'email' => $intent.'@example.test', 'password' => 'test-password',
            'password_confirmation' => 'test-password', 'intent' => $intent];
    }

    public function test_all_three_registration_choices_use_one_central_identity(): void
    {
        foreach (['client', 'provider', 'both'] as $index => $intent) {
            $this->post('/registro', $this->registration($intent))->assertRedirect('/login');
            $user = User::where('email', $intent.'@example.test')->firstOrFail();
            $expected = $intent === 'client' ? ['client'] : ['client', 'provider'];
            $this->assertSame($expected, $user->roles()->orderBy('slug')->pluck('slug')->all());
            $this->assertTrue(Hash::check('test-password', $user->password));
            $this->assertDatabaseCount('users', $index + 1);
            $this->assertDatabaseCount('profesionistas', 0);
        }
        $this->assertDatabaseCount('provider_profiles', 0);
    }

    public function test_invalid_registration_intent_and_password_confirmation_do_not_create_an_account(): void
    {
        $data = $this->registration();
        $data['intent'] = 'admin';
        $data['password_confirmation'] = 'different-password';
        $this->from('/registro')->post('/registro', $data)->assertRedirect('/registro')
            ->assertSessionHasErrors(['intent', 'password_confirmation']);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('role_user', 0);
    }

    public function test_the_complete_web_flow_preserves_one_account_and_both_roles(): void
    {
        $data = $this->registration();
        $this->post('/registro', $data)->assertRedirect('/login');
        $user = User::firstOrFail();
        $passwordHash = $user->password;
        $this->post('/login', ['email' => $data['email'], 'password' => $data['password']])->assertRedirect('/cuenta');
        $this->get('/cuenta')->assertOk()->assertSee('Convertirme en prestador');
        $this->get('/cuenta/activar-prestador')->assertOk()->assertSee('Tu acceso de cliente se conserva');
        $this->post('/cuenta/roles', ['role' => 'provider'])->assertStatus(303)->assertRedirect('/cuenta/prestador')
            ->assertSessionHas('success');
        $this->get('/cuenta/prestador')->assertOk()->assertSee('Completar mi presentación');
        $this->post('/perfil-profesional', ['display_name' => 'Nombre público', 'headline' => 'Soporte técnico', 'bio' => 'Mi experiencia.'])
            ->assertRedirect('/cuenta/prestador')->assertSessionHas('success');
        $profile = $user->providerProfile()->firstOrFail();
        $this->get('/perfiles-profesionales/'.$profile->id)->assertOk()->assertSee('Nombre público');
        $this->put('/perfiles-profesionales/'.$profile->id, ['display_name' => 'Presentación actualizada', 'headline' => 'Desarrollo', 'bio' => 'Nueva biografía.'])
            ->assertRedirect('/cuenta/prestador')->assertSessionHas('success');
        $this->get('/cuenta/cliente')->assertOk()->assertSee('Cambiar a Prestador');
        $this->get('/cuenta/prestador')->assertOk()->assertSee('Presentación actualizada');
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertSame($passwordHash, $user->fresh()->password);
        $this->assertSame(['client', 'provider'], $user->roles()->orderBy('slug')->pluck('slug')->all());
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('profesionistas', 0);
        $this->assertDatabaseCount('provider_profiles', 1);
    }

    public function test_provider_login_opens_the_profile_workspace(): void
    {
        $data = $this->registration('provider');
        $this->post('/registro', $data)->assertRedirect('/login');
        $this->post('/login', ['email' => $data['email'], 'password' => $data['password']])
            ->assertRedirect('/cuenta/prestador');
    }

    public function test_activation_is_idempotent_and_does_not_remove_client_access(): void
    {
        $user = $this->account(RoleName::Client);
        $this->actingAs($user, 'web');
        $this->post('/cuenta/roles', ['role' => 'provider'])->assertRedirect('/cuenta/prestador');
        $this->post('/cuenta/roles', ['role' => 'provider'])->assertRedirect('/cuenta/prestador');
        $this->get('/cuenta/cliente')->assertOk();
        $this->assertDatabaseCount('role_user', 2);
        $this->assertFalse($user->hasRole(RoleName::Admin));
    }

    public function test_profile_validation_preserves_input_and_prevents_unapproved_fields(): void
    {
        $user = $this->account(RoleName::Provider);
        $profile = $user->providerProfile()->create(['display_name' => 'Conservar']);
        $this->actingAs($user, 'web')->from('/cuenta/prestador')
            ->put('/perfiles-profesionales/'.$profile->id, ['display_name' => '', 'headline' => str_repeat('x', 161), 'bio' => 'Conservar entrada'])
            ->assertRedirect('/cuenta/prestador')->assertSessionHasErrors(['display_name', 'headline']);
        $this->get('/cuenta/prestador')->assertSee('Conservar entrada')->assertSee('Revisa los datos');
        $this->put('/perfiles-profesionales/'.$profile->id, ['display_name' => 'Manipulado', 'user_id' => 999, 'roles' => ['admin']])
            ->assertSessionHasErrors(['user_id', 'roles']);
        $this->assertSame('Conservar', $profile->fresh()->display_name);
        $this->assertSame($user->id, $profile->fresh()->user_id);
    }

    public function test_profile_duplicate_submission_shows_a_recoverable_message(): void
    {
        $user = $this->account(RoleName::Provider);
        $user->providerProfile()->create(['display_name' => 'Original']);
        $this->actingAs($user, 'web')->post('/perfil-profesional', ['display_name' => 'Duplicado'])
            ->assertRedirect('/cuenta/prestador')->assertSessionHas('error');
        $this->assertDatabaseCount('provider_profiles', 1);
        $this->assertDatabaseHas('provider_profiles', ['display_name' => 'Original']);
    }

    public function test_profile_html_is_private_and_escapes_user_content(): void
    {
        $owner = $this->account(RoleName::Provider);
        $other = $this->account(RoleName::Provider);
        $profile = $owner->providerProfile()->create(['display_name' => 'Perfil privado', 'bio' => '<script>alert(1)</script>']);
        $this->actingAs($other, 'web')->get('/perfiles-profesionales/'.$profile->id)->assertForbidden();
        $this->put('/perfiles-profesionales/'.$profile->id, ['display_name' => 'Otro nombre'])->assertForbidden();
        $this->actingAs($owner, 'web')->get('/perfiles-profesionales/'.$profile->id)->assertOk()
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_navigation_matches_permissions_for_all_role_combinations(): void
    {
        foreach ([[], [RoleName::Client], [RoleName::Provider], [RoleName::Client, RoleName::Provider], [RoleName::Admin]] as $roles) {
            $user = $this->account(...$roles);
            $response = $this->actingAs($user, 'web')->get('/cuenta')->assertOk();
            $links = [
                ['role' => RoleName::Client, 'route' => 'account.client'],
                ['role' => RoleName::Provider, 'route' => 'account.provider'],
                ['role' => RoleName::Admin, 'route' => 'identity.roles.index'],
            ];
            foreach ($links as $link) {
                $html = 'href="'.route($link['route']).'"';
                if (in_array($link['role'], $roles, true)) $response->assertSee($html, false);
                else $response->assertDontSee($html, false);
            }
        }
    }

    public function test_shared_navigation_is_available_from_existing_mindbloom_pages(): void
    {
        $this->actingAs($this->account(RoleName::Client, RoleName::Provider), 'web');
        foreach (['/', '/perfil', '/citas', '/profesionistas', '/comunidad', '/conferencias', '/perfil/editar-nombre'] as $path) {
            $this->get($path)->assertOk()->assertSee('href="'.route('account.provider').'"', false)
                ->assertSee('MindBloom anterior')->assertDontSee('href="'.route('identity.roles.index').'"', false);
        }
    }

    public function test_administration_is_read_only_for_authorized_web_users_and_hidden_from_clients(): void
    {
        $this->actingAs($this->account(RoleName::Client), 'web')->get('/administracion/roles')->assertForbidden();
        $this->actingAs($this->account(RoleName::Admin), 'web')->get('/administracion/roles')
            ->assertOk()->assertSee('Roles disponibles')->assertSee('Esta pantalla es de consulta.');
    }

    public function test_html_role_activation_rejects_admin_or_another_target(): void
    {
        $user = $this->account(RoleName::Client);
        $this->actingAs($user, 'web')->from('/cuenta/activar-prestador')
            ->post('/cuenta/roles', ['role' => 'admin'])->assertSessionHasErrors('role');
        $this->post('/cuenta/roles', ['role' => 'provider', 'user_id' => 999])->assertSessionHasErrors('user_id');
        $this->assertSame(['client'], $user->roles()->pluck('slug')->all());
    }

    public function test_legacy_login_is_explicit_and_preserves_existing_credentials_and_guard(): void
    {
        $legacy = Profesionista::create(['nombre' => 'Profesional anterior', 'email' => 'legacy@example.test',
            'password' => Hash::make('legacy-password'), 'cedula' => 'LEGACY-001', 'google_access_token' => 'fake-private-token']);
        $before = $legacy->fresh()->getAttributes();
        $this->get('/login?role=prof')->assertOk()->assertSee('Acceso heredado')->assertSee('solo para cuentas profesionales ya existentes');
        $this->post('/login-profesionista', ['email' => $legacy->email, 'password' => 'legacy-password'])
            ->assertRedirect('/dashboard-profesionista');
        $this->get('/dashboard-profesionista')->assertOk()->assertSee('Acceso heredado de MindBloom.')->assertDontSee('fake-private-token');
        $this->assertAuthenticatedAs($legacy, 'profesionista');
        $this->assertGuest('web');
        $this->assertSame($before, $legacy->fresh()->getAttributes());
        $this->assertDatabaseCount('users', 0);
    }

    public function test_legacy_signup_cannot_create_another_account_and_new_signup_does_not_link_matching_emails(): void
    {
        $legacy = Profesionista::create(['nombre' => 'Anterior', 'email' => 'provider@example.test',
            'password' => Hash::make('old-password'), 'cedula' => 'LEGACY-001']);
        $before = $legacy->fresh()->getAttributes();
        $this->postJson('/registro-profesionista', ['nombre' => 'No crear', 'email' => 'new@example.test', 'password' => 'test-password'])
            ->assertStatus(410)->assertJsonStructure(['message', 'registration_url']);
        $this->post('/registro', $this->registration('provider'))->assertRedirect('/login');
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('profesionistas', 1);
        $this->assertDatabaseCount('provider_profiles', 0);
        $this->assertSame($before, $legacy->fresh()->getAttributes());
    }

    public function test_registration_page_has_one_central_form_and_no_legacy_signup_action(): void
    {
        $this->get('/registro?intent=provider')->assertOk()
            ->assertSee('Contratar y ofrecer')->assertSee('name="intent" value="provider" checked', false)
            ->assertSee('action="'.route('registro.store').'"', false)
            ->assertDontSee('action="'.route('profesionista.store').'"', false);
    }
}
