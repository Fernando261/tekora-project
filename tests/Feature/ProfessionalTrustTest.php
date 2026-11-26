<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\ProfessionalCredential;
use App\Support\LegalPolicies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CatalogFixtures;
use Tests\Support\EnforcedCsrf;
use Tests\TestCase;

final class ProfessionalTrustTest extends TestCase
{
    use CatalogFixtures;

    public function test_registration_and_term_replay_record_only_one_acceptance(): void
    {
        $body = ['name' => 'Prestador sintético', 'email' => 'trust@example.test', 'age' => 25, 'gender' => 'Sin especificar',
            'password' => 'fixture-password', 'intent' => 'provider'];
        $this->postJson('/registro', $body)->assertUnprocessable()->assertJsonValidationErrors(['accept_terms', 'license_disposition']);
        $this->post('/registro', $body + ['accept_terms' => '1', 'terms_version' => LegalPolicies::VERSION, 'license_disposition' => 'none'])->assertRedirect('/login');
        $user = \App\Models\User::where('email', $body['email'])->sole();
        $this->assertDatabaseHas('professional_credentials', ['user_id' => $user->id, 'status' => 'unverified']);
        $first = DB::table('terms_acceptances')->where('user_id', $user->id)->sole();
        $this->travel(2)->days();
        $this->actingAs($user)->post(route('legal.accept'), ['accept_terms' => 1, 'terms_version' => LegalPolicies::VERSION])->assertRedirect();
        $this->assertEquals($first, DB::table('terms_acceptances')->where('user_id', $user->id)->sole());
    }

    public function test_cookie_controls_and_legal_pages_preserve_essential_session(): void
    {
        $this->withoutVite();
        $this->app->bind(ValidateCsrfToken::class, EnforcedCsrf::class);
        foreach (array_keys(LegalPolicies::TITLES) as $policy) {
            $this->get(route('legal.show', $policy))->assertOk();
        }
        $this->get('/login')->assertOk()->assertSee('Solo esenciales');
        $this->post(route('legal.cookies'), ['optional' => 'accepted'])->assertStatus(419);
        $this->withSession(['_token' => 'cookie-fixture'])->post(route('legal.cookies'), ['optional' => 'rejected', '_token' => 'cookie-fixture'])
            ->assertRedirect(route('legal.show', 'cookies'))->assertCookie('tekora_preferences_v1', 'rejected');
        $this->withCookie('tekora_preferences_v1', 'rejected')->get('/login')->assertOk()->assertDontSee('Solo esenciales');
        $this->post(route('legal.cookies'), ['optional' => 'accepted', '_token' => 'cookie-fixture'])->assertCookie('tekora_preferences_v1', 'accepted');
    }

    public function test_document_is_private_and_only_a_current_admin_decision_can_publish_badge_and_number(): void
    {
        $this->withoutVite();
        Storage::fake('professional');
        $provider = $this->account(RoleName::Provider);
        $admin = $this->account(RoleName::Admin);
        $client = $this->account(RoleName::Client);
        $service = $this->service($provider);
        $license = ['license_disposition' => 'available', 'license_number' => '1532467', 'public_license' => '1'];
        $this->actingAs($provider)->postJson(route('trust.update'), $license + ['certificate' => UploadedFile::fake()->createWithContent('falso.pdf', 'sitio HTML')])
            ->assertUnprocessable()->assertJsonValidationErrors('certificate');
        $this->postJson(route('trust.update'), $license + ['certificate' => UploadedFile::fake()->create('grande.pdf', 5121, 'application/pdf')])
            ->assertUnprocessable()->assertJsonValidationErrors('certificate');
        $pdf = UploadedFile::fake()->createWithContent('credencial.pdf', "%PDF-1.4\n% Synthetic school fixture\n%%EOF\n");
        $this->post(route('trust.update'), $license + ['certificate' => $pdf])->assertRedirect();
        $credential = ProfessionalCredential::findOrFail($provider->id);
        $this->assertSame('pending', $credential->status);
        Storage::disk('professional')->assertExists($credential->evidence_path);
        $this->get(route('trust.download', $provider))->assertDownload('certificado.pdf')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->getJson('/api/v1/services/'.$service->id)->assertOk()->assertJsonPath('data.provider.professional_credential.license_number', null);
        $decision = ['revision' => $credential->revision, 'decision' => 'verified', 'reason' => 'Evidencia sintética revisada'];
        $this->actingAs($client)->get(route('trust.download', $provider))->assertNotFound();
        $this->post(route('trust.decide', $provider), $decision)->assertForbidden();
        $this->actingAs($admin)->get(route('trust.download', $provider))->assertDownload('certificado.pdf');
        $this->post(route('trust.decide', $provider), $decision)->assertRedirect();
        $this->assertDatabaseHas('professional_reviews', ['provider_user_id' => $provider->id, 'reviewer_user_id' => $admin->id, 'decision' => 'verified']);
        $this->get(route('catalog.show', $service))->assertOk()->assertSee('Profesional Tekora')->assertSee('1532467')->assertDontSee($credential->evidence_path);
        $this->get(route('public-providers.show', $service->provider_profile_id))->assertOk()->assertSee('Profesional Tekora')
            ->assertDontSee('Perfil sin verificación profesional.');
        $this->actingAs($provider)->post(route('trust.update'), ['license_disposition' => 'available', 'license_number' => '1532467'])->assertRedirect();
        $this->getJson('/api/v1/services/'.$service->id)->assertJsonPath('data.provider.professional_credential.status', 'verified')->assertJsonPath('data.provider.professional_credential.license_number', null);
        $this->actingAs($admin)->post(route('trust.decide', $provider), array_replace($decision, ['decision' => 'revoked']))->assertRedirect();
        $this->get(route('catalog.show', $service))->assertOk()->assertDontSee('Profesional Tekora');
        $this->actingAs($provider)->post(route('trust.update'), array_replace($license, ['license_number' => '2532467']))->assertRedirect();
        $this->actingAs($admin)->post(route('trust.decide', $provider), $decision)->assertConflict();
        $this->assertSame('pending', $credential->fresh()->status);
        $this->post(route('trust.decide', $provider), [
            'revision' => $credential->fresh()->revision, 'decision' => 'rejected', 'reason' => 'Cédula distinta del documento',
        ])->assertRedirect();
        $this->assertSame('rejected', $credential->fresh()->status);
        $this->getJson('/api/v1/services/'.$service->id)->assertJsonPath('data.provider.professional_credential.badge', null);
    }

    public function test_location_filters_combine_with_search_category_and_verification(): void
    {
        $this->withoutVite();
        $provider = $this->account(RoleName::Provider);
        $service = $this->service($provider, ['title' => 'Soporte sintético', 'modality' => 'onsite']);
        $data = $this->serviceData(['title' => 'Soporte sintético', 'modality' => 'onsite', 'service_category_id' => $service->service_category_id]);
        $this->actingAs($provider)->putJson(route('provider-services.update', $service), $data)->assertUnprocessable()->assertJsonValidationErrors('municipality');
        $this->put(route('provider-services.update', $service), $data + ['municipality' => 'zapopan'])->assertRedirect();
        $this->post(route('trust.update'), ['license_disposition' => 'available', 'license_number' => '2244567'])->assertRedirect();
        $admin = $this->account(RoleName::Admin);
        $this->actingAs($admin)->post(route('trust.decide', $provider), ['revision' => 1, 'decision' => 'verified', 'reason' => 'Sintético'])->assertRedirect();
        $this->assertCount(9, $this->get('/api/v1/municipalities', ['Accept' => 'application/json'])->assertOk()->json('data'));
        $query = http_build_query(['municipality' => 'zapopan', 'verified' => '1', 'q' => 'Soporte', 'category' => $service->category->slug]);
        $this->getJson('/api/v1/services?'.$query)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.location.municipality.slug', 'zapopan');
        $this->getJson('/api/v1/services?municipality=tonala&verified=1')->assertOk()->assertJsonCount(0, 'data');
        $this->get('/servicios?'.$query)->assertOk()->assertSee('Profesional Tekora')->assertSee('Zapopan');
        $this->getJson('/api/v1/providers?municipality=zapopan&verified=1')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($provider)->put(route('provider-services.update', $service), array_replace($data, ['modality' => 'remote', 'municipality' => 'zapopan']))->assertRedirect();
        $this->getJson('/api/v1/services/'.$service->id)->assertJsonPath('data.location.remote', true)->assertJsonPath('data.location.municipality', null);
    }
}
