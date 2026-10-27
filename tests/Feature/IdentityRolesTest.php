<?php

namespace Tests\Feature;

use App\Enums\Permission;
use App\Enums\RoleName;
use App\Models\Profesionista;
use App\Models\ProviderProfile;
use App\Models\Role;
use App\Models\User;
use App\Services\RoleMemberships;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class IdentityRolesTest extends TestCase
{
    private function userWithRoles(RoleName ...$roles): User
    {
        $user = User::factory()->create();
        // Asignación explícita de fixtures; no existe bootstrap administrativo.
        $ids = Role::whereIn('slug', array_map(fn (RoleName $role) => $role->value, $roles))->pluck('id');
        $user->roles()->attach($ids);

        return $user;
    }

    private function profile(User $user): ProviderProfile
    {
        return $user->providerProfile()->create(['display_name' => 'Perfil de prueba']);
    }

    public function test_role_catalog_assigns_nothing_to_existing_identities(): void
    {
        $user = $this->userWithRoles();
        $this->assertDatabaseCount('roles', 3);
        $this->assertDatabaseCount('role_user', 0);
        $this->assertDatabaseCount('provider_profiles', 0);
        $this->assertFalse($user->hasRole(RoleName::Admin));
        $this->assertFalse($user->can(Permission::ManageAccountRoles->value));
    }

    public function test_new_registration_gets_only_client_even_when_privileged_fields_are_submitted(): void
    {
        $this->post('/registro', [
            'name' => 'Cliente', 'age' => 30, 'gender' => 'Prefiero no decirlo',
            'email' => 'client@example.test', 'password' => 'test-password',
            'role' => 'admin', 'roles' => ['admin', 'provider'], 'is_admin' => true,
        ])->assertRedirect('/login');

        $user = User::where('email', 'client@example.test')->firstOrFail();
        $this->assertSame(['client'], $user->roles()->pluck('slug')->all());
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('profesionistas', 0);
        $this->assertDatabaseCount('provider_profiles', 0);
    }

    public function test_one_account_can_explicitly_enroll_in_both_business_roles_idempotently(): void
    {
        $user = $this->userWithRoles();
        $before = $user->fresh()->getAttributes();
        $this->actingAs($user, 'web');

        foreach (['client', 'provider', 'provider', 'client'] as $role) {
            $this->postJson('/cuenta/roles', ['role' => $role])->assertOk();
        }

        $this->getJson('/cuenta/identidad')->assertOk()->assertJson([
            'user_id' => $user->id, 'roles' => ['client', 'provider'], 'provider_profile_id' => null,
        ])->assertJsonMissingPath('password');
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('role_user', 2);
        $this->assertSame($before, $user->fresh()->getAttributes());
        $this->assertFalse($user->hasRole(RoleName::Admin));
    }

    public function test_self_enrollment_rejects_admin_unknown_roles_and_target_identity_injection(): void
    {
        $user = $this->userWithRoles();
        $other = $this->userWithRoles();
        $this->actingAs($user, 'web');

        foreach (['admin', 'administrator', 'unknown'] as $role) {
            $this->postJson('/cuenta/roles', ['role' => $role])->assertUnprocessable()->assertJsonValidationErrors('role');
        }
        $this->postJson('/cuenta/roles', ['role' => 'provider', 'user_id' => $other->id])
            ->assertUnprocessable()->assertJsonValidationErrors('user_id');
        $this->assertDatabaseCount('role_user', 0);
    }

    public function test_internal_enrollment_also_rejects_administrative_roles(): void
    {
        $user = $this->userWithRoles();
        $this->expectException(AuthorizationException::class);
        app(RoleMemberships::class)->enroll($user, RoleName::Admin);
    }

    public function test_client_permission_controls_the_new_appointments_endpoint(): void
    {
        $client = $this->userWithRoles(RoleName::Client);
        $provider = $this->userWithRoles(RoleName::Provider);
        $admin = $this->userWithRoles(RoleName::Admin);
        $unassigned = $this->userWithRoles();

        $this->actingAs($client, 'web')->get('/cuenta/citas')->assertOk();
        foreach ([$provider, $admin, $unassigned] as $user) {
            $this->actingAs($user, 'web')->get('/cuenta/citas')->assertForbidden();
        }
    }

    public function test_legacy_user_flows_remain_available_without_new_roles(): void
    {
        $user = $this->userWithRoles();
        $this->actingAs($user, 'web')->get('/')->assertOk();
        $this->get('/citas')->assertOk();
        $this->get('/perfil')->assertOk();
        $this->assertDatabaseCount('role_user', 0);
    }

    public function test_provider_can_create_and_update_one_own_profile_without_creating_another_identity(): void
    {
        $user = $this->userWithRoles(RoleName::Client, RoleName::Provider);
        $this->actingAs($user, 'web')->postJson('/perfil-profesional', [
            'display_name' => 'Prestador', 'headline' => 'Soporte técnico', 'bio' => 'Presentación.',
        ])->assertCreated()->assertJsonPath('provider_profile.user_id', $user->id);
        $profile = $user->providerProfile()->firstOrFail();

        $this->getJson('/perfiles-profesionales/'.$profile->id)->assertOk();
        $this->putJson('/perfiles-profesionales/'.$profile->id, ['display_name' => 'Nombre actualizado'])
            ->assertOk()->assertJsonPath('provider_profile.display_name', 'Nombre actualizado');
        $this->postJson('/perfil-profesional', ['display_name' => 'Duplicado'])->assertConflict();

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('provider_profiles', 1);
        $this->assertDatabaseCount('profesionistas', 0);
        $this->assertSame($user->id, $profile->user->id);
    }

    public function test_profile_creation_requires_provider_permission_and_valid_data(): void
    {
        $client = $this->userWithRoles(RoleName::Client);
        $this->actingAs($client, 'web')->postJson('/perfil-profesional', ['display_name' => 'No permitido'])
            ->assertForbidden();

        app(RoleMemberships::class)->enroll($client, RoleName::Provider);
        $this->postJson('/perfil-profesional', ['display_name' => '', 'headline' => str_repeat('a', 161)])
            ->assertUnprocessable()->assertJsonValidationErrors(['display_name', 'headline']);
        $this->assertDatabaseCount('provider_profiles', 0);
    }

    public function test_a_profile_without_a_role_does_not_grant_permission(): void
    {
        $user = $this->userWithRoles();
        $profile = $this->profile($user);
        $this->assertFalse($user->hasPermission(Permission::ManageProviderProfile));
        $this->actingAs($user, 'web')->getJson('/perfiles-profesionales/'.$profile->id)->assertForbidden();
    }

    public function test_providers_cannot_read_or_modify_another_accounts_profile(): void
    {
        $owner = $this->userWithRoles(RoleName::Provider);
        $other = $this->userWithRoles(RoleName::Provider);
        $profile = $this->profile($owner);

        $this->actingAs($other, 'web')->getJson('/perfiles-profesionales/'.$profile->id)->assertForbidden();
        $this->putJson('/perfiles-profesionales/'.$profile->id, ['display_name' => 'Intento ajeno'])
            ->assertForbidden();
        $this->assertSame('Perfil de prueba', $profile->fresh()->display_name);
    }

    public function test_profile_payload_cannot_change_owner_roles_or_link_legacy_accounts(): void
    {
        $owner = $this->userWithRoles(RoleName::Provider);
        $other = $this->userWithRoles();
        $profile = $this->profile($owner);
        $this->actingAs($owner, 'web')->putJson('/perfiles-profesionales/'.$profile->id, [
            'display_name' => 'Manipulado', 'user_id' => $other->id,
            'profesionista_id' => 1, 'roles' => ['admin'], 'is_admin' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors(['user_id', 'profesionista_id', 'roles', 'is_admin']);

        $this->assertSame($owner->id, $profile->fresh()->user_id);
        $this->assertFalse($owner->hasRole(RoleName::Admin));
        $this->assertDatabaseCount('profesionistas', 0);
    }

    public function test_administrator_can_review_but_cannot_edit_someone_elses_profile(): void
    {
        $admin = $this->userWithRoles(RoleName::Admin);
        $profile = $this->profile($this->userWithRoles(RoleName::Provider));

        $this->actingAs($admin, 'web')->getJson('/perfiles-profesionales/'.$profile->id)->assertOk();
        $this->putJson('/perfiles-profesionales/'.$profile->id, ['display_name' => 'Otro nombre'])->assertForbidden();
    }

    public function test_business_role_management_requires_admin_and_preserves_the_target_identity(): void
    {
        $admin = $this->userWithRoles(RoleName::Admin);
        $target = $this->userWithRoles();
        $before = $target->fresh()->getAttributes();

        foreach ([RoleName::Client, RoleName::Provider] as $role) {
            $actor = $this->userWithRoles($role);
            $this->actingAs($actor, 'web')->getJson('/administracion/roles')->assertForbidden();
            $this->putJson('/administracion/usuarios/'.$target->id.'/roles', ['roles' => ['provider']])
                ->assertForbidden();
        }

        $this->actingAs($admin, 'web')->getJson('/administracion/roles')->assertOk()->assertJsonCount(3, 'roles');
        $this->putJson('/administracion/usuarios/'.$target->id.'/roles', ['roles' => ['client', 'provider']])
            ->assertOk()->assertJsonPath('roles', ['client', 'provider']);
        $this->assertSame($before, $target->fresh()->getAttributes());
        $this->assertFalse($target->hasRole(RoleName::Admin));
    }

    public function test_role_management_cannot_add_or_remove_administrative_membership(): void
    {
        $admin = $this->userWithRoles(RoleName::Admin, RoleName::Client);
        $target = $this->userWithRoles();
        $this->actingAs($admin, 'web');

        $this->putJson('/administracion/usuarios/'.$target->id.'/roles', ['roles' => ['admin']])
            ->assertUnprocessable()->assertJsonValidationErrors('roles.0');
        $this->putJson('/administracion/usuarios/'.$admin->id.'/roles', ['roles' => []])->assertOk();
        $this->assertTrue($admin->hasRole(RoleName::Admin));
        $this->assertFalse($admin->hasRole(RoleName::Client));
        $this->assertFalse($target->hasRole(RoleName::Admin));
    }

    public function test_role_revocation_is_effective_with_preloaded_relations_and_keeps_the_profile(): void
    {
        $admin = $this->userWithRoles(RoleName::Admin);
        $target = $this->userWithRoles(RoleName::Provider);
        $profile = $this->profile($target);
        $target->load('roles');
        $this->assertTrue($target->hasPermission(Permission::ManageProviderProfile));

        app(RoleMemberships::class)->replaceBusinessRoles($admin, $target, []);

        $this->assertFalse($target->hasPermission(Permission::ManageProviderProfile));
        $this->actingAs($target, 'web')->getJson('/perfiles-profesionales/'.$profile->id)->assertForbidden();
        $this->assertDatabaseHas('provider_profiles', ['id' => $profile->id, 'user_id' => $target->id]);
    }

    public function test_internal_role_management_does_not_bypass_authorization(): void
    {
        $actor = $this->userWithRoles(RoleName::Provider);
        $target = $this->userWithRoles();
        $this->expectException(AuthorizationException::class);
        app(RoleMemberships::class)->replaceBusinessRoles($actor, $target, [RoleName::Client]);
    }

    public function test_guests_cannot_access_new_endpoints(): void
    {
        foreach (['/cuenta/identidad', '/cuenta/citas', '/administracion/roles'] as $path) {
            $this->getJson($path)->assertUnauthorized();
        }
        $this->postJson('/cuenta/roles', ['role' => 'provider'])->assertUnauthorized();
        $this->postJson('/perfil-profesional', ['display_name' => 'No autorizado'])->assertUnauthorized();
    }

    public function test_legacy_professional_with_matching_id_and_email_cannot_inherit_central_roles(): void
    {
        $admin = $this->userWithRoles(RoleName::Admin, RoleName::Provider);
        $profile = $this->profile($admin);
        $legacy = Profesionista::create([
            'nombre' => 'Cuenta antigua', 'email' => $admin->email,
            'password' => Hash::make('legacy-password'), 'cedula' => 'LEGACY-001',
        ]);
        $this->assertSame($admin->id, $legacy->id);

        $this->assertFalse(Gate::forUser($legacy)->allows(Permission::ManageAccountRoles->value));
        $this->assertFalse(Gate::forUser($legacy)->allows('view', $profile));
        $this->actingAs($legacy, 'profesionista')->getJson('/cuenta/identidad')->assertUnauthorized();
        $this->getJson('/administracion/roles')->assertUnauthorized();
        $this->postJson('/cuenta/roles', ['role' => 'provider'])->assertUnauthorized();
        $this->get('/dashboard-profesionista')->assertOk();
        $this->assertDatabaseCount('users', 1);
    }
}
