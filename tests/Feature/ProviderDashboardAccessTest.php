<?php

namespace Tests\Feature;

use App\Enums\InquiryStatus;
use App\Enums\RoleName;
use App\Models\Profesionista;
use App\Services\ReservationWorkflow;
use App\Services\ReviewWorkflow;
use Illuminate\Support\Facades\DB;
use Tests\Support\DashboardFixtures;
use Tests\TestCase;

class ProviderDashboardAccessTest extends TestCase
{
    use DashboardFixtures;

    public function test_guests_and_legacy_professionals_require_central_web_authentication(): void
    {
        $this->get('/cuenta/prestador/dashboard')->assertRedirect(route('login'));
        $legacy = Profesionista::create(['nombre' => 'Profesional anterior', 'email' => 'anterior@example.test', 'password' => 'clave-anterior', 'cedula' => 'LEGACY-9A']);
        $this->actingAs($legacy, 'profesionista')->get('/cuenta/prestador/dashboard')->assertRedirect(route('login'));
        $this->assertDatabaseCount('users', 0); $this->assertDatabaseCount('provider_profiles', 0);
    }

    public function test_client_and_admin_do_not_receive_provider_access_implicitly(): void
    {
        foreach ([$this->account(RoleName::Client), $this->account(RoleName::Admin), $this->account(RoleName::Client, RoleName::Admin)] as $user) {
            $this->actingAs($user)->get(route('provider.dashboard'))->assertForbidden();
            $this->get(route('account.index'))->assertOk()->assertDontSee('href="'.route('provider.dashboard').'"', false);
        }
    }

    public function test_provider_without_profile_gets_setup_instructions_without_creating_data(): void
    {
        $provider = $this->account(RoleName::Provider);
        $response = $this->actingAs($provider)->get(route('provider.dashboard'))->assertOk()->assertSee('Completa tu perfil para empezar')
            ->assertSee('href="'.route('account.provider').'"', false)->assertDontSee('data-kpi=', false);
        $this->assertNull($response->viewData('dashboard')['profile']); $this->assertDatabaseCount('provider_profiles', 0);
        $this->assertDatabaseCount('services', 0); $this->assertDatabaseCount('activity_notifications', 0);
    }

    public function test_provider_without_activity_gets_ten_cards_safe_empty_states_and_all_quick_links(): void
    {
        $provider = $this->account(RoleName::Provider); $provider->providerProfile()->create(['display_name' => 'Taller Cero']);
        $response = $this->actingAs($provider)->get(route('provider.dashboard'))->assertOk()->assertSee('Taller Cero')->assertSee('Sin decisiones')
            ->assertSee('Sin reservas finalizadas')->assertSee('Sin calificaciones')->assertSee('No tienes próximas reservas confirmadas')
            ->assertSee('No tienes solicitudes pendientes')->assertSee('Todavía no hay reservas completadas');
        $this->assertSame(10, substr_count($response->getContent(), 'data-kpi='));
        foreach (['provider-services.index', 'availability.edit', 'inquiries.received', 'conversations.index', 'reservations.index', 'notifications.index', 'account.provider'] as $route) {
            $response->assertSee('href="'.route($route).'"', false);
        }
        $response->assertSee('method="GET"', false)->assertSee('name="period"', false)->assertSee('<table>', false)->assertSee('<details', false);
    }

    public function test_period_validation_rejects_arrays_arbitrary_values_and_identity_overrides_safely(): void
    {
        $provider = $this->account(RoleName::Provider); $this->service($provider);
        foreach (['period=0', 'period=8', 'period=365', 'period=-7', 'period=07', 'period=7.0', 'period=', 'period[]=7', 'period[days]=30', 'period=%3Cscript%3E', 'provider_id=1', 'user_id=1', 'profile_id=1', 'period=7&provider_id=999', 'page=2'] as $query) {
            $this->actingAs($provider)->get('/cuenta/prestador/dashboard?'.$query)->assertStatus(422)->assertSee('Elige un periodo de 7, 30 o 90 días')
                ->assertDontSee('SQLSTATE')->assertDontSee('/app/');
        }
        foreach ([7, 30, 90] as $period) $this->get(route('provider.dashboard', compact('period')))->assertOk()->assertSee('value="'.$period.'" selected', false);
    }

    public function test_two_providers_only_see_their_own_metrics_and_never_private_or_withdrawn_content(): void
    {
        [$client, $owner, $inquiry, $reservation] = $this->reviewContext(); $review = $this->publishReview($reservation, 1, 'RESEÑA-RETIRADA-SECRETA');
        $this->message($client, $inquiry, 'MENSAJE-SECRETO'); app(ReviewWorkflow::class)->withdraw($client, $review->id);
        $ownPending = $this->inquiry(null, $owner); $ownPending->service->update(['title' => 'SERVICIO-SOLO-PROPIO']);
        $other = $this->account(RoleName::Provider, RoleName::Admin); $other->providerProfile()->create(['display_name' => 'Segundo taller']);
        $otherData = $this->actingAs($other)->get(route('provider.dashboard'))->assertOk()->assertDontSee('SERVICIO-SOLO-PROPIO')
            ->assertDontSee($owner->providerProfile->display_name)->assertDontSee($client->name)->assertDontSee($client->email)->viewData('dashboard');
        $this->assertSame(0, $this->values($otherData)['received']); $this->assertSame(0, $this->values($otherData)['completed']);
        $this->assertSame('Sin calificaciones', $this->values($otherData)['rating']); $this->assertSame([], $otherData['attention']);
        $this->actingAs($owner)->get(route('provider.dashboard'))->assertOk()->assertSee('SERVICIO-SOLO-PROPIO')->assertDontSee('MENSAJE-SECRETO')
            ->assertDontSee('RESEÑA-RETIRADA-SECRETA')->assertDontSee($client->email)->assertDontSee($client->name)->assertDontSee(route('reviews.show', $review));
    }

    public function test_current_role_revocation_overrides_cached_roles_and_provider_admin_sees_only_self(): void
    {
        $provider = $this->account(RoleName::Provider, RoleName::Admin); $this->service($provider);
        $provider->load('roles'); $this->actingAs($provider)->get(route('provider.dashboard'))->assertOk();
        $provider->roles()->detach($provider->roles()->where('slug', 'provider')->value('roles.id'));
        $this->get(route('provider.dashboard'))->assertForbidden();
        $this->get(route('account.index'))->assertOk()->assertDontSee('href="'.route('provider.dashboard').'"', false);
    }

    public function test_dashboard_get_is_read_only_for_business_history_and_read_markers(): void
    {
        [$client, $provider, $inquiry, $reservation] = $this->reviewContext(); $this->publishReview($reservation);
        $this->message($client, $inquiry); $this->inquiry(null, $provider);
        $tables = DB::table('sqlite_master')->where('type', 'table')->where('name', 'not like', 'sqlite_%')->pluck('name')->all();
        $before = $this->reservationRows($tables); DB::enableQueryLog(); DB::flushQueryLog();
        foreach ([7, 30, 90] as $period) $this->actingAs($provider)->get(route('provider.dashboard', compact('period')))->assertOk();
        $queries = DB::getQueryLog(); DB::disableQueryLog();
        $this->assertSame($before, $this->reservationRows($tables));
        $writes = array_filter($queries, fn ($query) => preg_match('/^\s*(INSERT|UPDATE|DELETE|CREATE|DROP|ALTER|REPLACE)\b/i', $query['query']));
        $this->assertSame([], array_values($writes));
    }

    public function test_profile_names_and_service_titles_are_escaped_in_heading_agenda_and_attention(): void
    {
        [, $provider, $inquiry] = $this->reservationContext();
        $provider->providerProfile->update(['display_name' => '<img src=x onerror=PRIVATE-NAME()>']);
        $inquiry->service->update(['title' => '<script>PRIVATE-TITLE()</script>']);
        $reservation = $this->proposeReservation($inquiry); app(ReservationWorkflow::class)->decide($provider, $reservation->id, 'confirm', ['version' => 1]);
        $pending = $this->inquiry(null, $provider); $pending->service->update(['title' => '<svg onload=PRIVATE-PENDING()>']);
        $this->actingAs($provider)->get(route('provider.dashboard'))->assertOk()->assertSee('<img src=x onerror=PRIVATE-NAME()>')
            ->assertSee('<script>PRIVATE-TITLE()</script>')->assertSee('<svg onload=PRIVATE-PENDING()>')
            ->assertDontSee('<img src=x onerror=PRIVATE-NAME()>', false)->assertDontSee('<script>PRIVATE-TITLE()</script>', false)->assertDontSee('<svg onload=PRIVATE-PENDING()>', false);
    }

    public function test_dashboard_navigation_is_available_without_replacing_professional_profile_settings(): void
    {
        $provider = $this->account(RoleName::Client, RoleName::Provider); $this->service($provider);
        $this->actingAs($provider)->get(route('account.index'))->assertOk()->assertSee('Abrir panel del prestador')->assertSee('href="'.route('provider.dashboard').'"', false);
        $this->get(route('account.provider'))->assertOk()->assertSee('href="'.route('provider.dashboard').'"', false);
        $this->get(route('provider.dashboard'))->assertOk()->assertSee('Panel del prestador')->assertSee('Mi perfil profesional');
        $route = app('router')->getRoutes()->getByName('provider.dashboard');
        $this->assertContains('auth:web', $route->gatherMiddleware()); $this->assertSame('cuenta/prestador/dashboard', $route->uri());
    }

    public function test_temporary_database_failure_is_generic_even_with_debug_enabled(): void
    {
        $provider = $this->account(RoleName::Provider); $this->service($provider);
        DB::statement('DROP TABLE service_inquiry_reads'); config(['app.debug' => true]);
        $this->actingAs($provider)->get(route('provider.dashboard'))->assertStatus(503)->assertSee('El panel no está disponible temporalmente')
            ->assertDontSee('SQLSTATE')->assertDontSee('service_inquiry_reads')->assertDontSee('bindings')->assertDontSee('/app/');
    }
}
