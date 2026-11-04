<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Services\ProviderAvailability;
use Illuminate\Support\Facades\Route;
use Tests\Support\CatalogFixtures;
use Tests\TestCase;

class MobileApiCatalogTest extends TestCase
{
    use CatalogFixtures;

    public function test_public_catalog_and_profiles_share_visibility_rules_and_do_not_leak_private_fields(): void
    {
        $provider = $this->account(RoleName::Provider);
        $service = $this->service($provider);
        $private = $this->service($provider, ['status' => 'draft', 'title' => 'PRIVATE-DRAFT']);
        $this->service($provider, ['status' => 'paused', 'title' => 'PRIVATE-PAUSED']);
        $hidden = $this->service($this->account(RoleName::Provider));
        $hidden->category->update(['is_active' => false]);
        // Volver visible solo la categoría del servicio público.
        $service->update(['service_category_id' => $this->category('public')->id]);
        $revoked = $this->account(RoleName::Provider);
        $revokedService = $this->service($revoked, ['service_category_id' => $service->service_category_id]);
        $revoked->roles()->detach();
        $this->get('/api/v1/services', ['Accept' => 'text/html'])->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $service->id)->assertJsonPath('meta.total', 1);
        $detail = $this->get('/api/v1/services/'.$service->id)->assertOk()->json('data');
        $this->assertSame(['id', 'title', 'description', 'modality', 'category', 'provider'], array_keys($detail));
        $this->assertSame(['id', 'display_name', 'headline', 'bio'], array_keys($detail['provider']));
        $this->get('/api/v1/providers')->assertOk()->assertJsonCount(1, 'data');
        $this->get('/api/v1/providers/'.$provider->providerProfile->id)->assertOk()
            ->assertExactJson(['data' => $detail['provider']]);
        foreach ([$private, $hidden, $revokedService] as $nonpublic) {
            $this->get('/api/v1/services/'.$nonpublic->id)->assertNotFound();
            $this->get('/api/v1/services/'.$nonpublic->id.'/availability')->assertNotFound();
        }
        $this->get('/api/v1/providers/'.$revokedService->provider_profile_id)->assertNotFound();
        $serialized = json_encode($detail);
        foreach ([$provider->email, 'password', 'user_id', 'remember_token', 'google_', 'PRIVATE', 'revision', 'reviews', 'inquiries'] as $secret) {
            $this->assertStringNotContainsString($secret, $serialized);
        }
    }

    public function test_pagination_is_bounded_stable_and_filters_are_explicit(): void
    {
        $owner = $this->account(RoleName::Provider);
        $first = $this->service($owner, ['title' => '100% literal', 'modality' => 'both']);
        $this->service($owner, ['title' => '1000 other', 'modality' => 'onsite']);
        $third = $this->service($owner, ['title' => 'Third', 'modality' => 'remote']);
        $this->get('/api/v1/services?per_page=2')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $third->id)->assertJsonPath('meta', ['current_page' => 1, 'per_page' => 2, 'last_page' => 2, 'total' => 3]);
        $this->get('/api/v1/services?per_page=2&page=2')->assertOk()->assertJsonPath('data.0.id', $first->id);
        $this->get('/api/v1/services?q=100%25&modality=remote&category=soporte-tecnico')->assertOk()->assertJsonCount(1, 'data');
        $this->get('/api/v1/services?q='.urlencode("' OR 1=1 --"))->assertOk()->assertJsonCount(0, 'data');
        foreach (['per_page=51', 'page=0', 'page[]=1', 'category=nonexistent', 'modality=invalid', 'user_id=1', 'sort=password'] as $filter) {
            $this->get('/api/v1/services?'.$filter)->assertUnprocessable()->assertJsonPath('error.code', 'validation_failed');
        }
        $this->get('/api/v1/providers?q=Nombre&per_page=1')->assertOk()->assertJsonCount(1, 'data');
        $this->get('/api/v1/providers?modality=remote')->assertUnprocessable();
        $this->get('/api/v1/services/'.$first->id.'?include=user')->assertUnprocessable();
    }

    public function test_availability_projects_existing_civil_schedule_and_timezone_without_internal_ids(): void
    {
        $owner = $this->account(RoleName::Provider);
        $service = $this->service($owner);
        $this->get('/api/v1/services/'.$service->id.'/availability')->assertOk()->assertJsonPath('data.configured', false)
            ->assertJsonPath('data.timezone', null)->assertJsonPath('data.intervals', []);
        app(ProviderAvailability::class)->save($owner, ['revision' => 0, 'timezone' => 'America/Mexico_City',
            'intervals' => [['day_of_week' => 1, 'start' => '09:00', 'end' => '12:00']],
            'blocked_dates' => ['2030-01-01']]);
        $response = $this->get('/api/v1/services/'.$service->id.'/availability')->assertOk()
            ->assertJsonPath('data.timezone', 'America/Mexico_City')
            ->assertJsonPath('data.intervals.0', ['weekday' => 1, 'start_local' => '09:00', 'end_local' => '12:00'])
            ->assertJsonPath('data.blocked_dates', ['2030-01-01']);
        $this->assertMatchesRegularExpression('/Z$/', $response->json('data.queried_at'));
        foreach (['revision', 'start_minute', 'provider_profile_id', 'reservation', 'client', 'created_at'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
    }

    public function test_stored_xss_is_json_text_and_never_an_html_response(): void
    {
        $owner = $this->account(RoleName::Provider);
        $payload = '<script>alert("XSS")</script>';
        $service = $this->service($owner, ['title' => $payload, 'description' => $payload]);
        $owner->providerProfile->update(['display_name' => $payload, 'bio' => $payload]);
        $this->get('/api/v1/services/'.$service->id, ['Accept' => 'text/html'])->assertOk()
            ->assertHeader('Content-Type', 'application/json')->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertJsonPath('data.title', $payload)->assertJsonPath('data.provider.bio', $payload);
    }

    public function test_errors_remain_safe_json_with_debug_enabled_and_without_accept_header(): void
    {
        config(['app.debug' => true]);
        Route::middleware('api')->get('/api/v1/_test/failure', fn () => throw new \RuntimeException('SECRET SQL /internal/file.php'));
        Route::middleware('api')->get('/api/v1/_test/db', fn () => throw new \PDOException('SECRET SQL token-value'));
        Route::middleware('api')->get('/api/v1/_test/conflict', fn () => abort(409, 'SECRET'));
        Route::middleware('api')->get('/api/v1/_test/unavailable', fn () => abort(503, 'SECRET'));
        foreach (['/api/v1/not-found' => 404, '/api/v1/services/abc' => 404, '/api/v1/services/99999' => 404,
            '/api/v1/_test/failure' => 500, '/api/v1/_test/db' => 503, '/api/v1/_test/conflict' => 409,
            '/api/v1/_test/unavailable' => 503] as $path => $status) {
            $response = $this->get($path, ['Accept' => 'text/html'])->assertStatus($status)
                ->assertHeader('Content-Type', 'application/json')->assertHeader('Cache-Control', 'no-store, private')
                ->assertJsonStructure(['error' => ['code', 'message', 'fields']]);
            foreach (['SECRET', 'SQL', 'exception', 'trace', '<html', 'file.php', 'token-value'] as $secret) {
                $this->assertStringNotContainsString($secret, $response->getContent());
            }
        }
        $this->post('/api/v1/services')->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
    }

    public function test_availability_failure_is_503_instead_of_free_schedule(): void
    {
        $service = $this->service($this->account(RoleName::Provider));
        $this->mock(ProviderAvailability::class)->shouldReceive('publicFor')->once()->andReturn(['unavailable' => true]);
        $this->get('/api/v1/services/'.$service->id.'/availability')->assertStatus(503)
            ->assertJsonPath('error.code', 'temporarily_unavailable');
    }

    public function test_public_reads_have_a_rate_limit(): void
    {
        for ($i = 0; $i < 120; $i++) {
            $this->get('/api/v1/services')->assertOk();
        }
        $this->get('/api/v1/providers')->assertStatus(429)->assertHeader('Retry-After')->assertJsonPath('error.code', 'rate_limited');
    }
}
