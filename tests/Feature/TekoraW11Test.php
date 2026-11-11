<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use Tests\Support\Retired\Profesionista;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\CatalogFixtures;
use Tests\Support\EnforcedCsrf;
use Tests\TestCase;

#[Group('web-w11')]
class TekoraW11Test extends TestCase
{
    use CatalogFixtures;

    public function test_shared_ambient_is_present_once_on_public_catalog_profiles_and_access(): void
    {
        $service = $this->service($this->account(RoleName::Provider));
        foreach (['/', '/login', '/registro', '/servicios', '/prestadores', route('catalog.show', $service),
            route('public-providers.show', $service->providerProfile), '/forgot-password', '/reset-password/visual-test-token'] as $path) {
            $response = $this->get($path)->assertOk()->assertSee('tekora-ambient-page')->assertSee('aria-hidden="true" data-tekora-ambient', false);
            $this->assertSame(1, substr_count($response->getContent(), 'data-tekora-ambient'));
        }
    }

    public function test_all_marketplace_interiors_inherit_ambient_without_changing_authorization_or_data(): void
    {
        $user = $this->account(RoleName::Client, RoleName::Provider);
        $this->service($user);
        $roles = $user->roles()->pluck('slug')->all();
        $this->actingAs($user, 'web');
        foreach (['account.index', 'account.client', 'account.provider', 'provider.dashboard', 'provider-services.index',
            'provider-services.create', 'availability.edit', 'inquiries.index', 'inquiries.received', 'conversations.index',
            'reservations.index', 'reviews.index', 'notifications.index'] as $route) {
            $response = $this->get(route($route))->assertOk()->assertSee('tekora-interior')->assertSee('data-tekora-ambient');
            $this->assertSame(1, substr_count($response->getContent(), 'data-tekora-ambient'));
            $response->assertDontSee('resources/js/tekora/tekora.js');
        }
        $this->assertSame($roles, $user->roles()->pluck('slug')->all());
        $this->assertDatabaseCount('service_inquiries', 0);
        $this->assertDatabaseCount('service_reservations', 0);
        $this->assertDatabaseCount('services', 1);
    }

    public function test_retired_pages_are_absent_and_profile_keeps_native_identity(): void
    {
        $this->actingAs(User::factory()->create(), 'web');
        foreach (['/mindbloom/inicio', '/comunidad', '/citas', '/conferencias', '/dashboard-profesionista'] as $path) {
            $this->get($path)->assertNotFound();
        }
        $this->get('/perfil')->assertOk()->assertDontSee('MindBloom')->assertDontSee('tekora-interior');
    }

    public function test_both_switches_are_real_get_links_and_each_back_face_has_no_interactive_form(): void
    {
        foreach (['/login' => route('registro.create'), '/registro' => route('login')] as $path => $destination) {
            $response = $this->get($path)->assertOk();
            $document = new DOMDocument;
            @$document->loadHTML($response->getContent());
            $xpath = new DOMXPath($document);
            $this->assertSame(1, $xpath->query('//*[@data-auth-panel]')->length);
            $this->assertSame(1, $xpath->query('//*[@data-auth-content]/form')->length);
            $this->assertSame(0, $xpath->query('//*[contains(@class,"tk-auth-back")]//form | //*[contains(@class,"tk-auth-back")]//input | //*[contains(@class,"tk-auth-back")]//button')->length);
            $this->assertSame($destination, $xpath->query('//a[@data-auth-switch]')->item(0)->getAttribute('href'));
            $this->assertSame(0, $xpath->query('//*[@data-auth-content and (@hidden or @inert)]')->length);
            $this->assertSame(1, $xpath->query('//*[@data-auth-content]/form/input[@name="_token"]')->length);
        }
    }

    public function test_access_gets_preserve_csrf_errors_old_values_and_native_intended_post(): void
    {
        $this->app->bind(ValidateCsrfToken::class, EnforcedCsrf::class);
        $this->withSession(['_token' => 'w11-native-csrf'])->get('/login')->assertOk();
        $this->withHeader('Accept', 'text/html')->get('/registro?intent=both')->assertOk()->assertSee('value="both" checked', false);
        $this->post('/registro', ['_token' => 'wrong-token'])->assertStatus(419);
        $this->from('/registro')->post('/registro', ['_token' => 'w11-native-csrf', 'intent' => 'both', 'name' => '',
            'age' => 0, 'gender' => '', 'email' => 'invalid-w11', 'password' => 'short', 'password_confirmation' => 'different'])
            ->assertSessionHasErrors(['name', 'email', 'password']);
        $this->get('/registro')->assertOk()->assertSee('role="alert"', false)->assertSee('aria-invalid="true"', false)
            ->assertSee('value="invalid-w11"', false)->assertDontSee('value="short"', false)->assertSee('data-auth-content');
        $user = User::factory()->create();
        $this->withSession(['url.intended' => route('account.index'), '_token' => 'w11-native-csrf'])->get('/login')->assertOk();
        $this->post('/login', ['_token' => 'w11-native-csrf', 'email' => $user->email, 'password' => 'password'])->assertRedirect(route('account.index'));
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertNull(config('auth.guards.profesionista'));
    }
}
