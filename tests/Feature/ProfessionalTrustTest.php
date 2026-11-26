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

    public function test_existing_provider_is_prompted_on_login_until_declaring_even_without_a_number(): void
    {
        $this->withoutVite();
        $provider = $this->account(RoleName::Client, RoleName::Provider);
        $login = ['email' => $provider->email, 'password' => 'password'];
        $this->post('/login', $login)->assertRedirect(route('trust.onboarding'));
        $this->get(route('trust.onboarding'))->assertOk()->assertSee('Completa tu declaración de cédula');
        $this->postJson(route('trust.onboarding.store'), ['license_disposition' => 'available', 'license_number' => 'inválida'])
            ->assertUnprocessable()->assertJsonValidationErrors('license_number');
        $this->assertDatabaseMissing('professional_credentials', ['user_id' => $provider->id]);
        $this->post(route('trust.onboarding.store'), ['license_disposition' => 'none'])->assertRedirect(route('account.provider'));
        $this->assertDatabaseHas('professional_credentials', ['user_id' => $provider->id, 'license_number' => null]);
        $this->post('/logout')->assertRedirect('/login');
        $this->post('/login', $login)->assertRedirect(route('account.provider'));
        $this->get(route('trust.onboarding'))->assertRedirect(route('account.provider'));
        $this->actingAs($this->account(RoleName::Client))->get(route('trust.onboarding'))->assertForbidden();
    }

    public function test_cedula_consent_and_certificate_approval_are_independent(): void
    {
        $this->withoutVite();
        Storage::fake('professional');
        $provider = $this->account(RoleName::Provider);
        $admin = $this->account(RoleName::Admin);
        $client = $this->account(RoleName::Client);
        $service = $this->service($provider);
        $serviceApi = '/api/v1/services/'.$service->id;
        $professional = 'data.provider.professional_credential.';
        $license = ['license_disposition' => 'available', 'license_number' => '1532467', 'public_license' => '1'];
        $certificate = route('trust.certificate.update');
        $decision = ['revision' => 1, 'decision' => 'verified', 'reason' => 'Evidencia sintética revisada'];
        $this->actingAs($provider)->post(route('trust.update'), $license)->assertRedirect();
        $this->get(route('catalog.show', $service))->assertOk()->assertSee('Cédula declarada: 1532467')->assertDontSee('Profesional Tekora')->assertDontSee('Verificación:');
        $this->get('/servicios')->assertOk()->assertSee('Cédula declarada: 1532467');
        $this->get(route('public-providers.show', $service->provider_profile_id))->assertOk()->assertSee('Cédula declarada: 1532467')->assertDontSee('Profesional Tekora');
        $this->getJson($serviceApi)->assertOk()->assertJsonPath($professional.'license_number', '1532467')->assertJsonPath($professional.'badge', null);
        $this->getJson('/api/v1/services?verified=1')->assertOk()->assertJsonCount(0, 'data');

        $this->getJson('/api/v1/providers/'.$service->provider_profile_id)->assertOk()
            ->assertJsonPath('data.professional_credential.license_number', '1532467')
            ->assertJsonPath('data.professional_credential.badge', null);
        $credentialRow = DB::table('professional_credentials')->where('user_id', $provider->id);
        $credentialRow->update(['status' => 'verified']);
        $this->getJson($serviceApi)->assertJsonPath($professional.'badge', null);
        $this->getJson('/api/v1/services?verified=1')->assertJsonCount(0, 'data');
        $credentialRow->update(['status' => 'pending']);
        $this->actingAs($admin)->get(route('trust.review'))->assertOk()->assertDontSee('value="verified"', false);
        $this->post(route('trust.decide', $provider), $decision)->assertConflict();
        $this->assertDatabaseMissing('professional_reviews', ['provider_user_id' => $provider->id]);
        $this->actingAs($provider)->postJson($certificate, ['certificate' => UploadedFile::fake()->createWithContent('falso.pdf', 'sitio HTML')])
            ->assertUnprocessable()->assertJsonValidationErrors('certificate');
        $this->postJson($certificate, ['certificate' => UploadedFile::fake()->create('grande.pdf', 5121, 'application/pdf')])
            ->assertUnprocessable()->assertJsonValidationErrors('certificate');
        $this->post($certificate, ['certificate' => $this->certificate()])->assertRedirect();
        $credential = ProfessionalCredential::findOrFail($provider->id);
        $this->assertSame('pending', $credential->status);
        $storage = Storage::disk('professional');
        $storage->assertExists($credential->evidence_path);
        $this->get(route('trust.download', $provider))->assertDownload('certificado.pdf')->assertHeader('X-Content-Type-Options', 'nosniff');
        $decision['revision'] = $credential->revision;
        $this->actingAs($client)->get(route('trust.download', $provider))->assertNotFound();
        $this->post(route('trust.decide', $provider), $decision)->assertForbidden();

        $this->actingAs($admin)->get(route('trust.review'))->assertOk()->assertSee('value="verified"', false);
        $original = $storage->get($credential->evidence_path);
        $storage->put($credential->evidence_path, 'contenido incompatible');
        $this->get(route('trust.review'))->assertOk()->assertDontSee('value="verified"', false);
        $this->post(route('trust.decide', $provider), $decision)->assertConflict();
        $storage->delete($credential->evidence_path);
        $this->post(route('trust.decide', $provider), $decision)->assertConflict();
        $storage->put($credential->evidence_path, $original);
        $this->get(route('trust.download', $provider))->assertDownload('certificado.pdf');
        $this->post(route('trust.decide', $provider), $decision)->assertRedirect();
        $this->assertDatabaseHas('professional_reviews', ['provider_user_id' => $provider->id, 'reviewer_user_id' => $admin->id, 'decision' => 'verified']);
        $this->get(route('catalog.show', $service))->assertOk()->assertSee('Profesional Tekora')->assertSee('1532467')->assertDontSee($credential->evidence_path);

        $this->actingAs($provider)->post(route('trust.update'), ['license_disposition' => 'available', 'license_number' => '1532467'])->assertRedirect();
        $this->getJson($serviceApi)->assertJsonPath($professional.'badge', 'Profesional Tekora')->assertJsonPath($professional.'license_number', null);
        $this->post(route('trust.update'), ['license_disposition' => 'none', 'public_license' => '1'])->assertRedirect();
        $this->get(route('catalog.show', $service))->assertOk()->assertSee('Profesional Tekora')->assertDontSee('1532467');
        $this->getJson($serviceApi)->assertJsonPath($professional.'license_number', null);
        $this->post(route('trust.update'), array_replace($license, ['license_number' => '2532467']))->assertRedirect();
        $this->assertSame($decision['revision'], $credential->fresh()->revision);
        $this->getJson($serviceApi)->assertJsonPath($professional.'badge', 'Profesional Tekora')->assertJsonPath($professional.'license_number', '2532467');

        $this->post($certificate, ['certificate' => $this->certificate()])->assertRedirect();
        $storage->assertMissing($credential->evidence_path);
        $this->getJson($serviceApi)->assertJsonPath($professional.'badge', null)->assertJsonPath($professional.'license_number', '2532467');
        $this->actingAs($admin)->post(route('trust.decide', $provider), $decision)->assertConflict();
        $replacement = $credential->fresh();
        $this->post(route('trust.decide', $provider), array_replace($decision, ['revision' => $replacement->revision]))->assertRedirect();
        $this->getJson($serviceApi)->assertJsonPath($professional.'badge', 'Profesional Tekora');
        $this->actingAs($provider)->post($certificate, ['remove_certificate' => '1'])->assertRedirect();
        $storage->assertMissing($replacement->evidence_path);
        $this->getJson($serviceApi)->assertJsonPath($professional.'badge', null)->assertJsonPath($professional.'license_number', '2532467');
        $this->getJson('/api/v1/services?verified=1')->assertOk()->assertJsonCount(0, 'data');
        $this->assertSame('unverified', $credential->fresh()->status);

        $this->travel(61)->seconds();
        $this->post($certificate, ['certificate' => $this->certificate()])->assertRedirect();
        $decision['revision'] = $credential->fresh()->revision;
        $this->actingAs($admin)->post(route('trust.decide', $provider), array_replace($decision, ['decision' => 'rejected']))->assertRedirect();
        $this->assertSame('rejected', $credential->fresh()->status);
        $this->getJson($serviceApi)->assertJsonPath($professional.'badge', null)->assertJsonPath($professional.'license_number', '2532467');
        $this->actingAs($provider)->post($certificate, ['certificate' => $this->certificate()])->assertRedirect();
        $decision['revision'] = $credential->fresh()->revision;
        $this->actingAs($admin)->post(route('trust.decide', $provider), $decision)->assertRedirect();
        $this->post(route('trust.decide', $provider), array_replace($decision, ['decision' => 'revoked']))->assertRedirect();
        $this->getJson($serviceApi)->assertJsonPath($professional.'badge', null)->assertJsonPath($professional.'license_number', '2532467');
    }

    public function test_location_filters_combine_with_search_category_and_verification(): void
    {
        $this->withoutVite();
        Storage::fake('professional');
        $provider = $this->account(RoleName::Provider);
        $service = $this->service($provider, ['title' => 'Soporte sintético', 'modality' => 'onsite']);
        $data = $this->serviceData(['title' => 'Soporte sintético', 'modality' => 'onsite', 'service_category_id' => $service->service_category_id]);
        $this->actingAs($provider)->putJson(route('provider-services.update', $service), $data)->assertUnprocessable()->assertJsonValidationErrors('municipality');
        $this->put(route('provider-services.update', $service), $data + ['municipality' => 'zapopan'])->assertRedirect();
        $this->post(route('trust.update'), ['license_disposition' => 'available', 'license_number' => '2244567'])->assertRedirect();
        $this->post(route('trust.certificate.update'), ['certificate' => $this->certificate()])->assertRedirect();
        $admin = $this->account(RoleName::Admin);
        $this->actingAs($admin)->post(route('trust.decide', $provider), ['revision' => 2, 'decision' => 'verified', 'reason' => 'Sintético'])->assertRedirect();
        $this->assertCount(9, $this->get('/api/v1/municipalities', ['Accept' => 'application/json'])->assertOk()->json('data'));
        $query = http_build_query(['municipality' => 'zapopan', 'verified' => '1', 'q' => 'Soporte', 'category' => $service->category->slug]);
        $this->getJson('/api/v1/services?'.$query)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.location.municipality.slug', 'zapopan');
        $this->getJson('/api/v1/services?municipality=tonala&verified=1')->assertOk()->assertJsonCount(0, 'data');
        $this->get('/servicios?'.$query)->assertOk()->assertSee('Profesional Tekora')->assertSee('Zapopan');
        $this->getJson('/api/v1/providers?municipality=zapopan&verified=1')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($provider)->put(route('provider-services.update', $service), array_replace($data, ['modality' => 'remote', 'municipality' => 'zapopan']))->assertRedirect();
        $this->getJson('/api/v1/services/'.$service->id)->assertJsonPath('data.location.remote', true)->assertJsonPath('data.location.municipality', null);
    }

    private function certificate(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('credencial.pdf', "%PDF-1.4\n% Synthetic school fixture\n%%EOF\n");
    }
}
