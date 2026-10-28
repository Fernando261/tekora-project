<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Tests\Support\CatalogFixtures;
use Tests\Support\EnforcedCsrf;
use Tests\TestCase;

class ServiceCsrfTest extends TestCase
{
    use CatalogFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->bind(ValidateCsrfToken::class, EnforcedCsrf::class);
    }

    public function test_service_forms_and_all_writes_enforce_csrf_in_the_testing_environment(): void
    {
        $owner = $this->account(RoleName::Provider);
        $service = $this->service($owner, ['status' => 'draft']);
        $this->actingAs($owner, 'web')->withSession(['_token' => 'service-token']);
        foreach (['/cuenta/servicios/crear', '/cuenta/servicios/'.$service->id.'/editar', '/cuenta/servicios'] as $path) {
            $this->get($path)->assertOk()->assertSee('name="_token"', false);
        }
        $before = $service->fresh()->getAttributes();
        $this->post('/cuenta/servicios', $this->serviceData())->assertStatus(419);
        $this->put('/cuenta/servicios/'.$service->id, $this->serviceData() + ['_token' => 'wrong'])->assertStatus(419);
        $this->patch('/cuenta/servicios/'.$service->id.'/estado', ['status' => 'published'])->assertStatus(419);
        $this->assertSame($before, $service->fresh()->getAttributes());
        $this->post('/cuenta/servicios', $this->serviceData() + ['_token' => 'service-token'])->assertStatus(303);
        $this->put('/cuenta/servicios/'.$service->id, $this->serviceData(['status' => 'draft']) + ['_token' => 'service-token'])->assertStatus(303);
        $this->patch('/cuenta/servicios/'.$service->id.'/estado', ['status' => 'published', '_token' => 'service-token'])->assertStatus(303);
        $this->assertSame('published', $service->fresh()->status->value);
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->assertSame('testing', app()->environment());
    }

    public function test_one_account_can_register_activate_provider_publish_and_be_discovered_by_a_guest(): void
    {
        $this->withSession(['_token' => 'registration-token'])->post('/registro', [
            '_token' => 'registration-token', 'name' => 'Una sola cuenta', 'age' => 30, 'gender' => 'Prefiero no decirlo',
            'email' => 'new-provider@example.test', 'password' => 'test-password', 'password_confirmation' => 'test-password', 'intent' => 'client',
        ])->assertRedirect('/login');
        $this->post('/login', ['_token' => 'registration-token', 'email' => 'new-provider@example.test', 'password' => 'test-password'])
            ->assertRedirect('/cuenta');
        $token = session()->token();
        $this->post('/cuenta/roles', ['_token' => $token, 'role' => 'provider'])->assertRedirect('/cuenta/prestador');
        $this->post('/perfil-profesional', ['_token' => $token, 'display_name' => 'Mi presentación pública', 'headline' => 'Soporte técnico'])
            ->assertRedirect('/cuenta/prestador');
        $this->get('/cuenta/servicios/crear')->assertOk();
        $this->post('/cuenta/servicios', $this->serviceData(['title' => 'Oferta de cuenta recién creada', 'status' => 'draft']) + ['_token' => $token])
            ->assertStatus(303);
        $service = Service::firstOrFail();
        $this->get('/servicios/'.$service->id)->assertNotFound();
        $this->patch('/cuenta/servicios/'.$service->id.'/estado', ['_token' => $token, 'status' => 'published'])->assertStatus(303);
        $this->get('/cuenta/cliente')->assertOk();
        $this->assertSame(['client', 'provider'], User::firstOrFail()->roles()->orderBy('slug')->pluck('slug')->all());
        $this->post('/logout', ['_token' => $token])->assertRedirect('/login');
        $this->assertGuest('web');
        $this->get('/servicios?q=recién')->assertOk()->assertSee('Oferta de cuenta recién creada');
        $this->get('/prestadores/'.$service->provider_profile_id)->assertOk()->assertSee('Mi presentación pública');
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('provider_profiles', 1);
        $this->assertDatabaseCount('profesionistas', 0);
        $this->assertDatabaseCount('services', 1);
    }
}
