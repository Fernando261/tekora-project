<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CatalogFixtures;
use Tests\TestCase;

class RetiredProductTest extends TestCase
{
    use CatalogFixtures;

    public static function retiredContracts(): array
    {
        $inventory = json_decode(file_get_contents(__DIR__.'/../../docs/web/w6/evidence/W62A-removal-code.json'), true, flags: JSON_THROW_ON_ERROR);

        return array_column(array_map(fn ($route) => [
            'key' => $route['method'].' '.$route['uri'],
            'value' => [$route['method'], preg_replace('/\{[^}]+\}/', '1', $route['uri']), $route['name']],
        ], $inventory['routes_and_names']), 'value', 'key');
    }

    #[DataProvider('retiredContracts')]
    public function test_retired_contract_is_absent_for_guests_stale_sessions_and_central_users(string $method, string $uri, ?string $name): void
    {
        Http::preventStrayRequests();
        $user = $this->account(RoleName::Client, RoleName::Provider);
        // Deliberately retain an opaque old session; it must never become web auth.
        $sessionKey = 'login_profesionista_'.sha1(\Illuminate\Auth\SessionGuard::class);
        $queries = [];
        $record = false;
        DB::listen(function ($query) use (&$queries, &$record) {
            if ($record) {
                $queries[] = $query->sql;
            }
        });
        $snapshot = fn () => collect(['users', 'role_user', 'provider_profiles', 'service_inquiries',
            'service_reservations', 'profesionistas', 'citas', 'posts', 'comments', 'likes',
            'emotions', 'reminders', 'profesionista_disponibilidades', 'profesionista_bloqueos'])
            ->mapWithKeys(fn ($table) => [$table => DB::table($table)->get()->toArray()])->all();
        $before = $snapshot();

        $record = true;
        $this->call($method, $uri)->assertNotFound()->assertHeaderMissing('Location');
        $this->withSession([$sessionKey => $user->id])->call($method, $uri)
            ->assertNotFound()->assertHeaderMissing('Location');
        $this->assertGuest('web');
        $this->actingAs($user, 'web')->call($method, $uri)
            ->assertNotFound()->assertHeaderMissing('Location');
        $record = false;

        $this->assertSame([], $queries, 'Removed routes must not invoke domain handlers, model binding or Google.');
        $this->assertEquals($before, $snapshot());
        $this->assertNull(config('auth.guards.profesionista'));
        $this->assertNull(config('auth.providers.profesionistas'));
        $this->assertNull(config('services.google'));
        if ($name !== null) {
            $this->assertFalse(Route::has($name));
        }
    }

    public function test_oauth_callback_in_flight_cannot_use_code_or_change_stored_tokens(): void
    {
        Http::preventStrayRequests();
        DB::table('profesionistas')->insert([
            'nombre' => 'Synthetic retired account', 'email' => 'retired@example.test',
            'password' => 'synthetic-hash', 'cedula' => 'W62B-FIXTURE',
            'google_access_token' => 'synthetic-access', 'google_refresh_token' => 'synthetic-refresh',
        ]);
        $before = DB::table('profesionistas')->get()->toArray();
        $this->get('/google/callback?state=synthetic-state&code=synthetic-code')->assertNotFound()
            ->assertDontSee('synthetic-code')->assertDontSee('synthetic-access');
        $this->assertEquals($before, DB::table('profesionistas')->get()->toArray());
        $this->assertDatabaseCount('users', 0);
    }

    public function test_access_pages_and_all_native_account_roles_expose_only_tekora(): void
    {
        foreach (['/', '/login?role=prof', '/registro?intent=provider'] as $path) {
            $this->get($path)->assertOk()->assertDontSee('MindBloom')->assertDontSee('legacy-access')
                ->assertDontSee('/google/connect')->assertDontSee('/login-profesionista');
        }
        foreach ([[], [RoleName::Client], [RoleName::Provider], [RoleName::Client, RoleName::Provider], [RoleName::Admin]] as $roles) {
            $user = $this->account(...$roles);
            foreach (['/cuenta', '/perfil', '/perfil/editar-nombre', '/cuenta/activar-prestador'] as $path) {
                $this->actingAs($user, 'web')->get($path)->assertOk()->assertDontSee('MindBloom')
                    ->assertDontSee('/mindbloom/')->assertDontSee('/cuenta/citas')->assertDontSee('legacy-access')
                    ->assertSee('action="'.route('logout').'"', false);
            }
        }
    }
}
