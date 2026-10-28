<?php

namespace Tests\Feature;

use App\Enums\InquiryStatus;
use App\Enums\RoleName;
use App\Models\ServiceInquiry;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Hash;
use Tests\Support\EnforcedCsrf;
use Tests\Support\InquiryFixtures;
use Tests\TestCase;

class InquiryCsrfTest extends TestCase
{
    use InquiryFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->bind(ValidateCsrfToken::class, EnforcedCsrf::class);
    }

    public function test_creation_and_all_decisions_require_csrf_and_a_token_does_not_replace_authorization(): void
    {
        $client = $this->account(RoleName::Client);
        $provider = $this->account(RoleName::Provider);
        $inquiry = $this->inquiry($client, $provider);
        $before = $inquiry->getAttributes();
        $this->actingAs($client, 'web')->withSession(['_token' => 'inquiry-token']);
        $this->post(route('inquiries.store', $inquiry->service_id), ['message' => 'Necesito revisar mi equipo.'])->assertStatus(419);
        $this->patch(route('inquiries.cancel', $inquiry), ['_token' => 'wrong'])->assertStatus(419);
        $this->actingAs($provider, 'web');
        foreach (['accept', 'reject', 'complete'] as $action) $this->patch(route('inquiries.'.$action, $inquiry))->assertStatus(419);
        $this->get(route('inquiries.show', $inquiry))->assertOk()->assertSee('name="_token"', false);
        $this->assertSame($before, $inquiry->fresh()->getAttributes());
        $this->actingAs($this->account(RoleName::Provider), 'web')->patch(route('inquiries.accept', $inquiry), ['_token' => 'inquiry-token'])->assertForbidden();
        $this->actingAs($provider, 'web')->patch(route('inquiries.accept', $inquiry), ['_token' => 'inquiry-token', 'status' => 'completed'])
            ->assertSessionHasErrors('status');
        $this->actingAs($client, 'web')->patch(route('inquiries.cancel', $inquiry), ['_token' => 'inquiry-token'])->assertStatus(303);
        $this->assertSame(InquiryStatus::Cancelled, $inquiry->fresh()->status);
        $this->assertSame('testing', app()->environment());
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
    }

    public function test_guest_can_register_log_in_request_and_follow_provider_acceptance_and_completion_with_real_csrf(): void
    {
        $provider = $this->account(RoleName::Provider);
        $provider->update(['password' => Hash::make('test-password')]);
        $service = $this->service($provider);
        $this->get(route('inquiries.create', $service))->assertRedirect('/login');
        $this->withSession(['_token' => 'before-login'])->post('/registro', [
            '_token' => 'before-login', 'name' => 'Cliente de prueba', 'age' => 30, 'gender' => 'Prefiero no decirlo',
            'email' => 'inquiry-client@example.test', 'password' => 'test-password', 'password_confirmation' => 'test-password', 'intent' => 'client',
        ])->assertRedirect('/login');
        $this->post('/login', ['_token' => 'before-login', 'email' => 'inquiry-client@example.test', 'password' => 'test-password'])
            ->assertRedirect(route('inquiries.create', $service));
        $token = session()->token();
        $this->assertNotSame('before-login', $token);
        $this->get(route('inquiries.create', $service))->assertOk()->assertSee('name="_token"', false);
        $this->post(route('inquiries.store', $service), ['_token' => 'before-login', 'message' => 'Necesito resolver un problema con mi equipo.'])->assertStatus(419);
        $this->post(route('inquiries.store', $service), ['_token' => $token, 'message' => 'Necesito resolver un problema con mi equipo.'])->assertStatus(303);
        $inquiry = ServiceInquiry::firstOrFail();
        $this->post('/logout', ['_token' => $token])->assertRedirect('/login');
        $this->post('/login', ['_token' => session()->token(), 'email' => $provider->email, 'password' => 'test-password'])->assertRedirect('/cuenta/prestador');
        $token = session()->token();
        $this->get('/cuenta/solicitudes/recibidas')->assertOk()->assertSee('Necesito resolver un problema con mi equipo.');
        $this->patch(route('inquiries.accept', $inquiry), ['_token' => $token])->assertStatus(303);
        $this->patch(route('inquiries.complete', $inquiry), ['_token' => 'wrong'])->assertStatus(419);
        $this->patch(route('inquiries.complete', $inquiry), ['_token' => $token])->assertStatus(303);
        $this->post('/logout', ['_token' => $token])->assertRedirect('/login');
        $this->post('/login', ['_token' => session()->token(), 'email' => 'inquiry-client@example.test', 'password' => 'test-password'])->assertRedirect('/cuenta');
        $this->get(route('inquiries.show', $inquiry))->assertOk()->assertSee('Finalizada')->assertSee('Historial de decisiones');
        $this->assertSame(InquiryStatus::Completed, $inquiry->fresh()->status);
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('service_inquiries', 1);
        $this->assertDatabaseCount('profesionistas', 0);
        $this->assertDatabaseCount('citas', 0);
    }
}
