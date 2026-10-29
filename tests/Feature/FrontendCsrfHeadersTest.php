<?php

namespace Tests\Feature;

use App\Enums\InquiryStatus;
use App\Enums\RoleName;
use App\Models\ServiceInquiry;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Tests\Support\CatalogFixtures;
use Tests\Support\EnforcedCsrf;
use Tests\TestCase;

class FrontendCsrfHeadersTest extends TestCase
{
    use CatalogFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->bind(ValidateCsrfToken::class, EnforcedCsrf::class);
    }

    public function test_ajax_cookie_header_contract_accepts_the_laravel_xsrf_cookie_and_rejects_a_tampered_header(): void
    {
        $client = $this->account(RoleName::Client);
        $service = $this->service($this->account(RoleName::Provider));
        $form = $this->actingAs($client, 'web')->get(route('inquiries.create', $service))->assertOk();
        $cookie = $form->getCookie('XSRF-TOKEN', false);
        $this->assertNotNull($cookie);
        $this->withHeader('X-Requested-With', 'XMLHttpRequest')->withHeader('Accept', 'application/json, text/plain, */*')
            ->withHeader('X-XSRF-TOKEN', $cookie->getValue())
            ->post(route('inquiries.store', $service), ['message' => 'Necesito revisar mi equipo de trabajo.'])->assertStatus(303);
        $inquiry = ServiceInquiry::firstOrFail();
        $this->assertSame($client->id, $inquiry->client_user_id);
        $this->assertSame(InquiryStatus::Pending, $inquiry->status);
        $this->withHeader('X-XSRF-TOKEN', 'tampered-cookie')
            ->post(route('inquiries.store', $service), ['message' => 'No debe escribirse sin un token válido.'])->assertStatus(419);
        $this->assertDatabaseCount('service_inquiries', 1);
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
    }

    public function test_ajax_meta_token_header_does_not_bypass_role_ownership_or_request_validation(): void
    {
        $client = $this->account(RoleName::Client);
        $service = $this->service($this->account(RoleName::Provider));
        $this->actingAs($client, 'web')->withSession(['_token' => 'meta-test-token'])
            ->withHeader('X-Requested-With', 'XMLHttpRequest')->withHeader('Accept', 'application/json, text/plain, */*')
            ->withHeader('X-CSRF-TOKEN', 'meta-test-token')
            ->post(route('inquiries.store', $service), ['message' => 'Necesito revisar mi equipo.', 'client_user_id' => 999, 'roles' => ['admin']])
            ->assertUnprocessable()->assertJsonValidationErrors(['client_user_id', 'roles']);
        $this->assertDatabaseCount('service_inquiries', 0);
        $this->actingAs($this->account(RoleName::Provider), 'web')
            ->post(route('inquiries.store', $service), ['message' => 'Un token válido no concede el rol Cliente.'])->assertForbidden();
        $this->actingAs($client, 'web')->withHeader('X-CSRF-TOKEN', 'wrong-token')
            ->post(route('inquiries.store', $service), ['message' => 'No debe escribirse con un token inválido.'])->assertStatus(419);
        $this->assertDatabaseCount('service_inquiries', 0);
    }
}
