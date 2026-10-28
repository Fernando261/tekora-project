<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\Profesionista;
use App\Models\Service;
use Illuminate\Support\Facades\Hash;
use Tests\Support\CatalogFixtures;
use Tests\TestCase;

class ServiceManagementTest extends TestCase
{
    use CatalogFixtures;

    public function test_provider_can_create_multiple_services_for_the_same_profile(): void
    {
        $owner = $this->account(RoleName::Client, RoleName::Provider);
        $profile = $owner->providerProfile()->create(['display_name' => 'Prestador central']);
        $this->actingAs($owner, 'web')->get('/cuenta/servicios/crear')->assertOk()->assertSee('Crear servicio');
        foreach (['draft', 'published'] as $status) {
            $this->post('/cuenta/servicios', $this->serviceData(['status' => $status]))->assertStatus(303)->assertSessionHas('success');
        }
        $this->assertDatabaseCount('services', 2);
        $this->assertSame([$profile->id], Service::pluck('provider_profile_id')->unique()->values()->all());
        $this->assertSame(['draft', 'published'], $profile->services()->orderBy('id')->get()->map(fn ($service) => $service->status->value)->all());
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('provider_profiles', 1);
        $this->assertDatabaseCount('profesionistas', 0);
    }

    public function test_guests_clients_admins_and_users_without_roles_cannot_manage_services(): void
    {
        $this->get('/cuenta/servicios')->assertRedirect('/login');
        $this->post('/cuenta/servicios', $this->serviceData())->assertRedirect('/login');
        foreach ([[], [RoleName::Client], [RoleName::Admin]] as $roles) {
            $this->actingAs($this->account(...$roles), 'web')->get('/cuenta/servicios')->assertForbidden();
            $this->get('/cuenta/servicios/crear')->assertForbidden();
            $this->post('/cuenta/servicios', $this->serviceData())->assertForbidden();
        }
        $this->assertDatabaseCount('services', 0);
    }

    public function test_a_provider_without_a_profile_gets_guidance_and_cannot_create_a_service(): void
    {
        $this->actingAs($this->account(RoleName::Provider), 'web')->get('/cuenta/servicios')
            ->assertOk()->assertSee('Completa primero tu perfil profesional');
        $this->get('/cuenta/servicios/crear')->assertForbidden();
        $this->post('/cuenta/servicios', $this->serviceData())->assertForbidden();
        $this->assertDatabaseCount('services', 0);
    }

    public function test_service_form_validates_categories_states_and_modalities_and_preserves_input(): void
    {
        $owner = $this->account(RoleName::Provider);
        $this->service($owner);
        $category = $this->category('inactive');
        $category->update(['is_active' => false]);
        $this->actingAs($owner, 'web')->from('/cuenta/servicios/crear')->post('/cuenta/servicios', $this->serviceData([
            'title' => '', 'description' => 'Conservar descripción', 'service_category_id' => $category->id,
            'modality' => 'telepathy', 'status' => 'verified',
        ]))->assertRedirect('/cuenta/servicios/crear')->assertSessionHasErrors(['title', 'service_category_id', 'modality', 'status']);
        $this->get('/cuenta/servicios/crear')->assertOk()->assertSee('Conservar descripción')->assertSee('Elige una modalidad válida.');
        $this->post('/cuenta/servicios', $this->serviceData(['service_category_id' => 99999]))->assertSessionHasErrors('service_category_id');
        $this->post('/cuenta/servicios', $this->serviceData(['title' => str_repeat('x', 161), 'description' => str_repeat('x', 5001)]))
            ->assertSessionHasErrors(['title', 'description']);
        $this->assertDatabaseCount('services', 1);
    }

    public function test_owner_can_edit_and_other_providers_cannot_read_private_editor_or_mutate_services(): void
    {
        $owner = $this->account(RoleName::Provider);
        $other = $this->account(RoleName::Provider);
        $service = $this->service($owner, ['status' => 'draft']);
        $this->actingAs($owner, 'web')->put('/cuenta/servicios/'.$service->id, $this->serviceData(['title' => 'Nombre actualizado', 'status' => 'draft']))
            ->assertRedirect('/cuenta/servicios/'.$service->id.'/editar');
        $before = $service->fresh()->getAttributes();
        foreach ([$other, $this->account(RoleName::Admin)] as $intruder) {
            $this->actingAs($intruder, 'web')->get('/cuenta/servicios/'.$service->id.'/editar')->assertForbidden();
            $this->put('/cuenta/servicios/'.$service->id, $this->serviceData())->assertForbidden();
            foreach (['published', 'paused', 'draft'] as $status) {
                $this->patch('/cuenta/servicios/'.$service->id.'/estado', ['status' => $status])->assertForbidden();
            }
        }
        $this->assertSame($before, $service->fresh()->getAttributes());
    }

    public function test_owner_can_publish_pause_republish_and_withdraw_to_draft(): void
    {
        $owner = $this->account(RoleName::Provider);
        $service = $this->service($owner, ['status' => 'draft']);
        $this->actingAs($owner, 'web');
        foreach (['published', 'paused', 'published', 'draft'] as $status) {
            $this->patch('/cuenta/servicios/'.$service->id.'/estado', ['status' => $status])->assertStatus(303)->assertSessionHas('success');
            $this->assertSame($status, $service->fresh()->status->value);
        }
        $this->patch('/cuenta/servicios/'.$service->id.'/estado', ['status' => 'deleted'])->assertSessionHasErrors('status');
        $this->assertDatabaseCount('services', 1);
    }

    public function test_category_deactivation_blocks_publication_but_allows_pausing_and_drafting(): void
    {
        $owner = $this->account(RoleName::Provider);
        $service = $this->service($owner);
        $service->category->update(['is_active' => false]);
        $this->actingAs($owner, 'web')->patch('/cuenta/servicios/'.$service->id.'/estado', ['status' => 'paused'])->assertRedirect('/cuenta/servicios');
        $this->patch('/cuenta/servicios/'.$service->id.'/estado', ['status' => 'published'])->assertSessionHasErrors('status');
        $this->assertSame('paused', $service->fresh()->status->value);
        $this->patch('/cuenta/servicios/'.$service->id.'/estado', ['status' => 'draft'])->assertRedirect('/cuenta/servicios');
        $this->get('/cuenta/servicios/'.$service->id.'/editar')->assertOk()->assertSee('La categoría anterior está inactiva');
        $this->put('/cuenta/servicios/'.$service->id, $this->serviceData(['service_category_id' => $this->category('new-active')->id]))
            ->assertRedirect('/cuenta/servicios/'.$service->id.'/editar');
        $this->assertSame('published', $service->fresh()->status->value);
    }

    public function test_ownership_and_role_payloads_are_rejected_on_every_write(): void
    {
        $owner = $this->account(RoleName::Provider);
        $service = $this->service($owner, ['status' => 'draft']);
        $before = $service->fresh()->getAttributes();
        $attack = ['user_id' => 999, 'provider_profile_id' => 999, 'roles' => ['admin'], 'role' => 'admin', 'is_admin' => true];
        $this->actingAs($owner, 'web')->post('/cuenta/servicios', $this->serviceData() + $attack)->assertSessionHasErrors(array_keys($attack));
        $this->put('/cuenta/servicios/'.$service->id, $this->serviceData() + $attack)->assertSessionHasErrors(array_keys($attack));
        $this->patch('/cuenta/servicios/'.$service->id.'/estado', ['status' => 'published'] + $attack)->assertSessionHasErrors(array_keys($attack));
        $this->assertSame($before, $service->fresh()->getAttributes());
        $this->assertDatabaseCount('services', 1);
        $this->assertFalse($owner->hasRole(RoleName::Admin));
        $model = new Service(['provider_profile_id' => 999, 'user_id' => 999, 'roles' => ['admin'], 'title' => 'Seguro']);
        $this->assertSame(['title' => 'Seguro'], $model->getAttributes());
    }

    public function test_role_revocation_immediately_blocks_service_writes(): void
    {
        $owner = $this->account(RoleName::Client, RoleName::Provider);
        $service = $this->service($owner);
        $owner->roles()->detach($owner->roles()->where('slug', 'provider')->firstOrFail()->id);
        $this->actingAs($owner, 'web')->get('/cuenta/servicios')->assertForbidden();
        $this->put('/cuenta/servicios/'.$service->id, $this->serviceData())->assertForbidden();
        $this->patch('/cuenta/servicios/'.$service->id.'/estado', ['status' => 'paused'])->assertForbidden();
        $this->assertSame('published', $service->fresh()->status->value);
    }

    public function test_same_account_can_switch_experiences_and_only_sees_its_own_services(): void
    {
        $owner = $this->account(RoleName::Client, RoleName::Provider);
        $service = $this->service($owner, ['title' => 'Servicio propio']);
        $this->service($this->account(RoleName::Provider), ['title' => 'Servicio ajeno privado', 'status' => 'draft']);
        $this->actingAs($owner, 'web')->get('/cuenta/cliente')->assertOk()->assertSee('Explorar servicios')->assertSee('Mis servicios');
        $this->get('/cuenta/servicios')->assertOk()->assertSee('Servicio propio')->assertDontSee('Servicio ajeno privado');
        $this->get('/cuenta/prestador')->assertOk()->assertSee('Crear servicio');
        $this->get('/servicios/'.$service->id)->assertOk();
        $this->get('/cuenta/cliente')->assertOk();
        $this->assertAuthenticatedAs($owner, 'web');
        $this->assertSame(['client', 'provider'], $owner->roles()->orderBy('slug')->pluck('slug')->all());
    }

    public function test_navigation_does_not_offer_service_management_to_clients_or_admin_only_accounts(): void
    {
        foreach ([RoleName::Client, RoleName::Admin] as $role) {
            $this->actingAs($this->account($role), 'web')->get('/cuenta')->assertOk()->assertSee('Explorar servicios')
                ->assertSee('Explorar prestadores')->assertDontSee('href="'.route('provider-services.index').'"', false);
        }
    }

    public function test_legacy_professional_authentication_never_owns_central_services(): void
    {
        $owner = $this->account(RoleName::Provider);
        $service = $this->service($owner);
        $legacy = Profesionista::create(['nombre' => 'Cuenta antigua', 'email' => $owner->email, 'cedula' => 'OLD-CATALOG-1',
            'password' => Hash::make('legacy-password'), 'google_access_token' => 'private-old-token']);
        $before = $legacy->fresh()->getAttributes();
        $this->post('/login-profesionista', ['email' => $legacy->email, 'password' => 'legacy-password'])->assertRedirect('/dashboard-profesionista');
        $this->get('/cuenta/servicios')->assertRedirect('/login');
        $this->put('/cuenta/servicios/'.$service->id, $this->serviceData())->assertRedirect('/login');
        $this->get('/dashboard-profesionista')->assertOk();
        $this->get('/profesionista/disponibilidad')->assertOk();
        $this->get('/servicios')->assertOk()->assertDontSee('private-old-token');
        $this->assertSame($before, $legacy->fresh()->getAttributes());
        $this->assertGuest('web');
        $this->assertDatabaseCount('provider_profiles', 1);
    }
}
