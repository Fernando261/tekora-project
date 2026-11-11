<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\CatalogFixtures;
use Tests\Support\EnforcedCsrf;
use Tests\TestCase;

#[Group('web-w1')]
class TekoraW1Test extends TestCase
{
    use CatalogFixtures;

    public function test_public_home_is_real_catalog_content_with_empty_states_and_no_dashboard_mocks(): void
    {
        $this->get('/')->assertOk()->assertViewIs('tekora.home')->assertSee('Tu próxima idea.')
            ->assertSee('El próximo encuentro está por llegar.')->assertSee('tekora-menu')->assertSee('Saltar al contenido')
            ->assertDontSee('Vista del panel')->assertDontSee('Google Calendar');
        $this->assertGuest('web');
        $this->assertSame('/', parse_url(route('tekora.home'), PHP_URL_PATH) ?? '/');
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('home'));
    }

    public function test_home_only_exposes_publicly_authorized_services_and_profiles(): void
    {
        $owner = $this->account(RoleName::Provider);
        $public = $this->service($owner, ['title' => 'Servicio visible W1']);
        $this->service($owner, ['status' => 'draft', 'title' => 'PRIVATE-DRAFT']);
        $this->service($owner, ['status' => 'paused', 'title' => 'PRIVATE-PAUSED']);
        $inactive = $this->category('inactive');
        $inactive->update(['is_active' => false]);
        $this->service($owner, ['service_category_id' => $inactive->id, 'title' => 'PRIVATE-CATEGORY']);
        $revoked = $this->account(RoleName::Provider);
        $hidden = $this->service($revoked, ['title' => 'PRIVATE-REVOKED']);
        $revoked->roles()->detach();
        $response = $this->get('/')->assertOk()->assertSee('Servicio visible W1')->assertSee($owner->providerProfile->display_name)
            ->assertSee(route('catalog.show', $public->id), false)->assertSee(route('catalog.index', ['category' => $public->category->slug]), false);
        foreach (['PRIVATE-DRAFT', 'PRIVATE-PAUSED', 'PRIVATE-CATEGORY', 'PRIVATE-REVOKED', $owner->email, $revoked->email, $hidden->providerProfile->display_name] as $secret) {
            $response->assertDontSee($secret);
        }
    }

    public function test_blade_escapes_hostile_content_and_home_never_creates_business_records(): void
    {
        $owner = $this->account(RoleName::Provider);
        $service = $this->service($owner, ['title' => '<script>alert("XSS")</script>']);
        $owner->providerProfile->update(['display_name' => '<img src=x onerror=alert(1)>']);
        $before = DB::table('services')->count();
        $response = $this->get('/')->assertOk()->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(', false);
        $this->assertSame($before, DB::table('services')->count());
        $this->assertDatabaseCount('service_inquiries', 0);
        $this->assertDatabaseCount('service_reservations', 0);
    }

    public function test_search_delegates_to_existing_catalog_validation_and_links_are_real(): void
    {
        $this->get('/')->assertOk()->assertSee('action="'.route('catalog.index').'"', false)->assertSee('maxlength="120"', false)
            ->assertSee(route('registro.create', ['intent' => 'provider']), false)->assertSee(route('login'), false);
        $this->get('/servicios?q='.str_repeat('x', 121))->assertStatus(422);
    }

    public function test_retired_product_pages_no_longer_exist(): void
    {
        foreach (['/mindbloom/inicio', '/comunidad', '/citas'] as $path) {
            $this->get($path)->assertNotFound();
            $this->actingAs(User::factory()->create(), 'web')->get($path)->assertNotFound();
        }
    }

    public function test_central_auth_forms_keep_fields_posts_and_old_values(): void
    {
        $this->get('/login?role=prof')->assertOk()->assertSee('action="'.route('login.post').'"', false)
            ->assertDontSee('legacy-access')->assertDontSee('MindBloom')
            ->assertSee('autocomplete="current-password"', false)->assertSee(route('password.request'), false);
        $response = $this->get('/registro?intent=provider')->assertOk()->assertSee('value="provider" checked', false);
        foreach (['intent', 'name', 'age', 'gender', 'email', 'password', 'password_confirmation', '_token'] as $name) {
            $response->assertSee('name="'.$name.'"', false);
        }
        $response->assertDontSee('accounts.google.com')->assertDontSee('appleid.apple.com');
    }

    public function test_real_csrf_validation_errors_and_intended_destination_survive_new_access_shell(): void
    {
        $this->app->bind(ValidateCsrfToken::class, EnforcedCsrf::class);
        $this->post('/login', ['email' => 'client@example.test', 'password' => 'password'])->assertStatus(419);
        $this->withSession(['_token' => 'w1-csrf'])->from('/registro?intent=both')->post('/registro', [
            '_token' => 'w1-csrf', 'intent' => 'both', 'name' => '', 'age' => 0, 'gender' => '', 'email' => 'not-email', 'password' => 'short', 'password_confirmation' => 'different',
        ])->assertSessionHasErrors(['name', 'email', 'password']);
        $this->get('/registro')->assertOk()->assertSee('role="alert"', false)->assertSee('aria-invalid="true"', false)->assertSee('value="not-email"', false)->assertDontSee('value="short"', false);
        $user = User::factory()->create();
        $this->get(route('account.index'))->assertRedirect('/login');
        $this->withSession(['_token' => 'w1-new-csrf'])->post('/login', ['_token' => 'w1-new-csrf', 'email' => $user->email, 'password' => 'password'])->assertRedirect(route('account.index'));
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertNull(config('auth.guards.profesionista'));
    }

    public function test_no_role_or_guard_is_changed_by_visiting_public_pages(): void
    {
        foreach ([RoleName::Client, RoleName::Provider, RoleName::Admin] as $role) {
            $user = $this->account($role);
            $before = $user->roles()->pluck('slug')->all();
            $this->actingAs($user, 'web')->get('/')->assertOk()->assertSee(route('account.index'), false);
            $this->assertSame($before, $user->roles()->pluck('slug')->all());
        }
    }

    public function test_new_pages_have_local_assets_csp_and_no_remote_auth_or_scene_scripts(): void
    {
        foreach (['/', '/login', '/registro'] as $path) {
            $response = $this->get($path)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
            $this->assertStringContainsString("script-src 'self'", $response->headers->get('Content-Security-Policy'));
            $response->assertDontSee('fonts.googleapis.com')->assertDontSee('spline-viewer')->assertDontSee('cdn.jsdelivr.net');
        }
        $this->assertMatchesRegularExpression('/prefers-reduced-motion:\s*reduce/', file_get_contents(resource_path('css/tekora/tekora.css')));
        $this->assertMatchesRegularExpression('/prefers-reduced-transparency:\s*reduce/', file_get_contents(resource_path('css/tekora/tekora.css')));
        $this->assertStringContainsString('from "animejs"', file_get_contents(resource_path('js/tekora/tekora.js')));
    }
}
