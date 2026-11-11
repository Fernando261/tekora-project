<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Tests\Support\EnforcedCsrf;
use Tests\TestCase;

class RoutesAndGoogleSafetyTest extends TestCase
{
    public function test_native_private_pages_still_require_central_authentication(): void
    {
        foreach (['/perfil', '/perfil/editar-nombre', '/cuenta', '/cuenta/identidad'] as $path) {
            $this->get($path)->assertRedirect('/login');
        }
    }

    public function test_native_profile_pages_keep_post_logout_and_hide_unimplemented_links(): void
    {
        $this->actingAs(User::factory()->create(), 'web');
        foreach (['/perfil', '/perfil/editar-nombre', '/cuenta'] as $path) {
            $this->get($path)->assertOk()
                ->assertSee('action="'.route('logout').'"', false)
                ->assertSee('method="POST"', false)
                ->assertDontSee('href="/reuniones"', false)
                ->assertDontSee('href="/notificaciones"', false)
                ->assertDontSee('MindBloom');
        }
    }

    public function test_logout_still_requires_csrf_and_invalidates_central_authentication(): void
    {
        $this->app->bind(ValidateCsrfToken::class, EnforcedCsrf::class);
        $user = User::factory()->create();
        $this->actingAs($user, 'web')->withSession(['_token' => 'native-logout-token']);
        $this->get('/logout')->assertStatus(405);
        $this->post('/logout')->assertStatus(419);
        $this->assertAuthenticatedAs($user, 'web');
        $this->post('/logout', ['_token' => 'native-logout-token'])->assertRedirect('/login');
        $this->assertGuest('web');
    }
}
