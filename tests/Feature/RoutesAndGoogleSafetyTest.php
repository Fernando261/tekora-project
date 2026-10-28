<?php

namespace Tests\Feature;

use App\Models\Emotion;
use App\Models\Profesionista;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Tests\TestCase;

class RoutesAndGoogleSafetyTest extends TestCase
{
    private function professional(): Profesionista
    {
        return Profesionista::create([
            'nombre' => 'Profesional de prueba', 'email' => 'professional@example.test',
            'password' => Hash::make('test-password'), 'cedula' => 'TEST-001',
            'google_access_token' => 'fake-sensitive-access-marker',
            'google_refresh_token' => 'fake-sensitive-refresh-marker',
        ]);
    }

    public function test_guests_are_directed_to_the_login_for_each_guard(): void
    {
        foreach (['/', '/perfil', '/comunidad', '/profesionistas', '/citas', '/consultas'] as $path) {
            $this->get($path)->assertRedirect('/login');
        }

        foreach (['/dashboard-profesionista', '/solicitudes-citas', '/profesionista/disponibilidad', '/google/connect'] as $path) {
            $this->get($path)->assertRedirect('/login?role=prof');
        }
    }

    public function test_user_authentication_does_not_authorize_professional_routes(): void
    {
        $this->actingAs(User::factory()->create(), 'web')->get('/dashboard-profesionista')
            ->assertRedirect('/login?role=prof');
    }

    public function test_professional_authentication_does_not_authorize_user_routes(): void
    {
        $this->actingAs($this->professional(), 'profesionista')->get('/perfil')->assertRedirect('/login');
    }

    public function test_professional_entry_points_select_the_existing_professional_panel(): void
    {
        $this->get('/login-profesionista')->assertRedirect('/login?role=prof');
        $this->get('/registro-profesionista')->assertRedirect('/registro?intent=provider');
        $this->get('/registro?role=prof')->assertOk()->assertSee('Crear mi cuenta única')->assertDontSee('action="'.route('profesionista.store').'"', false);
    }

    public function test_existing_user_pages_render_without_links_to_unimplemented_modules(): void
    {
        $this->actingAs(User::factory()->create(), 'web');

        foreach (['/', '/perfil', '/perfil/editar-nombre', '/comunidad', '/profesionistas', '/citas', '/conferencias'] as $path) {
            $this->get($path)->assertOk()
                ->assertDontSee('href="/reuniones"', false)
                ->assertDontSee('href="/notificaciones"', false);
        }

        $this->get('/consultas')->assertRedirect('/citas');
        $this->get('/reuniones')->assertNotFound();
    }

    public function test_professional_pages_use_their_logout_and_do_not_link_to_a_user_profile(): void
    {
        $this->actingAs($this->professional(), 'profesionista');

        foreach (['/dashboard-profesionista', '/solicitudes-citas', '/profesionista/disponibilidad'] as $path) {
            $this->get($path)->assertOk()
                ->assertSee('action="'.route('profesionista.logout').'"', false)
                ->assertDontSee('href="/perfil"', false)
                ->assertDontSee('fake-sensitive-access-marker')
                ->assertDontSee('fake-sensitive-refresh-marker');
        }
    }

    public function test_emotion_detail_resolves_the_model_and_missing_slugs_return_404(): void
    {
        Emotion::create(['name' => 'Calma', 'slug' => 'calma', 'image_path' => 'emotions/calma.png']);
        $this->get('/emociones/calma')->assertOk()->assertSee('Calma');
        $this->get('/emociones/unknown')->assertNotFound();
    }

    public function test_google_tokens_are_excluded_from_array_and_json_serialization(): void
    {
        $professional = $this->professional()->fresh();
        $this->assertArrayNotHasKey('google_access_token', $professional->toArray());
        $this->assertArrayNotHasKey('google_refresh_token', $professional->toArray());
        $this->assertStringNotContainsString('fake-sensitive-', $professional->toJson());
        $this->assertSame('fake-sensitive-access-marker', $professional->google_access_token);
    }

    public function test_oauth_errors_do_not_log_provider_messages_or_replace_existing_tokens(): void
    {
        $professional = $this->professional();
        Socialite::shouldReceive('driver')->with('google')->once()->andReturnSelf();
        Socialite::shouldReceive('user')->once()->andThrow(new \RuntimeException('fake-sensitive-provider-error'));
        Log::spy();

        $this->actingAs($professional, 'profesionista')->get('/google/callback')
            ->assertRedirect('/dashboard-profesionista')->assertSessionHas('error');

        Log::shouldHaveReceived('warning')->once()->with('No se pudo conectar Google Calendar', [
            'profesionista_id' => $professional->id, 'exception' => \RuntimeException::class, 'code' => 0,
        ]);
        $this->assertSame('fake-sensitive-access-marker', $professional->fresh()->google_access_token);
        $this->assertSame('fake-sensitive-refresh-marker', $professional->fresh()->google_refresh_token);
    }

    public function test_successful_oauth_preserves_the_previous_refresh_token_when_google_omits_it(): void
    {
        $professional = $this->professional();
        Socialite::shouldReceive('driver')->with('google')->once()->andReturnSelf();
        Socialite::shouldReceive('user')->once()->andReturn((object) [
            'token' => 'fake-replacement-access', 'refreshToken' => null, 'expiresIn' => 3600,
        ]);

        $this->actingAs($professional, 'profesionista')->get('/google/callback')
            ->assertRedirect('/dashboard-profesionista')->assertSessionHas('success');

        $this->assertSame('fake-replacement-access', $professional->fresh()->google_access_token);
        $this->assertSame('fake-sensitive-refresh-marker', $professional->fresh()->google_refresh_token);
        $this->assertSame('primary', $professional->fresh()->google_calendar_id);
    }
}
