<?php

namespace Tests\Feature;

use App\Enums\InquiryAction;
use App\Enums\InquiryStatus;
use App\Enums\RoleName;
use App\Models\Profesionista;
use App\Models\ServiceInquiry;
use App\Services\InquiryWorkflow;
use Illuminate\Support\Facades\Hash;
use Tests\Support\InquiryFixtures;
use Tests\TestCase;

class ServiceInquiriesTest extends TestCase
{
    use InquiryFixtures;

    public function test_client_submits_a_pending_inquiry_with_trusted_ownership_and_title_snapshot(): void
    {
        $client = $this->account(RoleName::Client);
        $service = $this->service($this->account(RoleName::Provider));
        $this->actingAs($client, 'web')->get(route('inquiries.create', $service))->assertOk()->assertSee('Confirmar y enviar solicitud');
        $this->post(route('inquiries.store', $service), ['message' => 'Necesito revisar el equipo de trabajo.'])
            ->assertStatus(303)->assertSessionHas('success');
        $inquiry = ServiceInquiry::firstOrFail();
        $this->assertSame($client->id, $inquiry->client_user_id);
        $this->assertSame($service->id, $inquiry->service_id);
        $this->assertSame($service->title, $inquiry->service_title);
        $this->assertSame(InquiryStatus::Pending, $inquiry->status);
        foreach (['accepted_at', 'rejected_at', 'cancelled_at', 'completed_at'] as $column) $this->assertNull($inquiry->$column);
        $this->assertDatabaseCount('citas', 0);
        $this->get(route('inquiries.show', $inquiry))->assertOk()->assertSee('Necesito revisar el equipo de trabajo.')->assertSee('Pendiente');
    }

    public function test_guest_entry_preserves_intended_destination_and_does_not_create_inquiries(): void
    {
        $service = $this->service($this->account(RoleName::Provider));
        $this->get('/servicios/'.$service->id)->assertOk()->assertSee('Solicitar servicio');
        $this->get(route('inquiries.create', $service))->assertRedirect('/login')->assertSessionHas('url.intended', route('inquiries.create', $service));
        $this->post(route('inquiries.store', $service), ['message' => 'Necesito ayuda con mi equipo.'])->assertRedirect('/login');
        $this->postJson(route('inquiries.store', $service), ['message' => 'Necesito ayuda con mi equipo.'])->assertUnauthorized();
        $this->assertDatabaseCount('service_inquiries', 0);
    }

    public function test_users_without_client_role_including_admin_cannot_send_or_access_client_panel(): void
    {
        $service = $this->service($this->account(RoleName::Provider));
        foreach ([[], [RoleName::Provider], [RoleName::Admin]] as $roles) {
            $this->actingAs($this->account(...$roles), 'web')->get(route('inquiries.create', $service))->assertForbidden();
            $this->post(route('inquiries.store', $service), ['message' => 'Necesito revisar mi equipo.'])->assertForbidden();
            $this->get('/cuenta/solicitudes')->assertForbidden();
            $this->get('/servicios/'.$service->id)->assertOk()->assertDontSee('href="'.route('inquiries.create', $service).'"', false);
        }
        $this->assertDatabaseCount('service_inquiries', 0);
    }

    public function test_all_visibility_failures_block_get_and_post_at_submission_time(): void
    {
        $client = $this->account(RoleName::Client);
        $provider = $this->account(RoleName::Provider);
        $service = $this->service($provider);
        $this->actingAs($client, 'web');
        foreach (['draft', 'paused'] as $status) {
            $service->update(['status' => $status]);
            $this->get(route('inquiries.create', $service))->assertNotFound();
            $this->post(route('inquiries.store', $service), ['message' => 'Necesito revisar mi equipo.'])->assertNotFound();
        }
        $service->update(['status' => 'published']);
        $service->category->update(['is_active' => false]);
        $this->post(route('inquiries.store', $service), ['message' => 'Necesito revisar mi equipo.'])->assertNotFound();
        $service->category->update(['is_active' => true]);
        $provider->roles()->detach();
        // Cambiar de cliente evita alcanzar el límite de envíos de la prueba.
        $this->actingAs($this->account(RoleName::Client), 'web')->post(route('inquiries.store', $service), ['message' => 'Necesito revisar mi equipo.'])->assertNotFound();
        $this->assertDatabaseCount('service_inquiries', 0);
    }

    public function test_a_dual_role_account_cannot_request_its_own_service(): void
    {
        $owner = $this->account(RoleName::Client, RoleName::Provider);
        $service = $this->service($owner);
        $this->actingAs($owner, 'web')->get('/servicios/'.$service->id)->assertOk()->assertSee('Este es un servicio propio');
        $this->get(route('inquiries.create', $service))->assertForbidden();
        $this->post(route('inquiries.store', $service), ['message' => 'Intento solicitar mi servicio.'])->assertForbidden();
        $this->assertDatabaseCount('service_inquiries', 0);
    }

    public function test_message_validation_keeps_input_and_does_not_accept_contact_fields(): void
    {
        $service = $this->service($this->account(RoleName::Provider));
        $this->actingAs($this->account(RoleName::Client), 'web')->from(route('inquiries.create', $service));
        foreach (['', 'corto', str_repeat('a', 2001), ['bad']] as $message) {
            $this->post(route('inquiries.store', $service), ['message' => $message])->assertSessionHasErrors('message');
        }
        $this->get(route('inquiries.create', $service))->assertOk();
        $this->post(route('inquiries.store', $service), ['message' => 'Conservar el texto introducido', 'email' => 'private@example.test'])
            ->assertSessionHasErrors('email');
        $this->get(route('inquiries.create', $service))->assertOk()->assertSee('Conservar el texto introducido');
        $this->assertDatabaseCount('service_inquiries', 0);
    }

    public function test_manipulated_owner_roles_state_and_timestamps_are_rejected_and_mass_assignment_is_guarded(): void
    {
        $service = $this->service($this->account(RoleName::Provider));
        $attack = ['client_user_id' => 999, 'user_id' => 999, 'provider_profile_id' => 999, 'service_id' => 999,
            'roles' => ['admin'], 'is_admin' => true, 'status' => 'accepted', 'service_title' => 'Alterado', 'accepted_at' => '2030-01-01'];
        $client = $this->account(RoleName::Client);
        $this->actingAs($client, 'web')->postJson(route('inquiries.store', $service), ['message' => 'Necesito revisar mi equipo.'] + $attack)
            ->assertUnprocessable()->assertJsonValidationErrors(array_keys($attack));
        $this->assertDatabaseCount('service_inquiries', 0);
        $this->assertFalse($client->hasRole(RoleName::Admin));
        $model = new ServiceInquiry(['message' => 'Texto permitido'] + $attack);
        $this->assertSame(['message' => 'Texto permitido'], $model->getAttributes());
    }

    public function test_repeated_pending_and_accepted_submissions_return_the_original_without_changing_message(): void
    {
        $client = $this->account(RoleName::Client);
        $provider = $this->account(RoleName::Provider);
        $inquiry = $this->inquiry($client, $provider);
        $this->actingAs($client, 'web');
        foreach ([InquiryStatus::Pending, InquiryStatus::Accepted] as $status) {
            $before = $inquiry->fresh()->getAttributes();
            $this->post(route('inquiries.store', $inquiry->service_id), ['message' => 'No reemplazar el envío original.'])
                ->assertRedirect(route('inquiries.show', $inquiry))->assertSessionHas('info');
            $this->assertSame($before, $inquiry->fresh()->getAttributes());
            $this->get(route('inquiries.create', $inquiry->service_id))->assertRedirect(route('inquiries.show', $inquiry));
            if ($status === InquiryStatus::Pending) app(InquiryWorkflow::class)->decide($provider, $inquiry->id, InquiryAction::Accept);
        }
        $this->assertDatabaseCount('service_inquiries', 1);
    }

    public function test_terminal_inquiries_allow_a_new_request_without_overwriting_history(): void
    {
        $client = $this->account(RoleName::Client);
        $provider = $this->account(RoleName::Provider);
        $this->actingAs($client, 'web');
        foreach ([InquiryStatus::Rejected, InquiryStatus::Cancelled, InquiryStatus::Completed] as $terminal) {
            $old = $this->inquiry($client, $provider, $terminal);
            $before = $old->getAttributes();
            $this->post(route('inquiries.store', $old->service_id), ['message' => 'Nueva necesidad después del cierre.'])->assertStatus(303);
            $this->assertSame($before, $old->fresh()->getAttributes());
        }
        $this->assertDatabaseCount('service_inquiries', 6);
    }

    public function test_history_and_inbox_are_filtered_paginated_and_scoped_to_the_current_account(): void
    {
        $client = $this->account(RoleName::Client);
        $provider = $this->account(RoleName::Provider);
        for ($i = 0; $i < 13; $i++) $this->inquiry($client, $provider);
        $accepted = $this->inquiry($client, $provider, InquiryStatus::Accepted);
        $foreign = $this->inquiry();
        $list = $this->actingAs($client, 'web')->get('/cuenta/solicitudes')->assertOk()->viewData('inquiries');
        $this->assertSame(14, $list->total());
        $this->assertCount(12, $list);
        $this->assertNotContains($foreign->id, $list->pluck('id')->all());
        $this->assertCount(2, $this->get('/cuenta/solicitudes?page=2')->assertOk()->viewData('inquiries'));
        $filtered = $this->get('/cuenta/solicitudes?status=accepted')->assertOk()->viewData('inquiries');
        $this->assertSame([$accepted->id], $filtered->pluck('id')->all());
        $this->actingAs($provider, 'web')->get('/cuenta/solicitudes/recibidas?status=pending')->assertOk();
        $this->assertSame(13, $this->get('/cuenta/solicitudes/recibidas?status=pending')->viewData('inquiries')->total());
        foreach (['status=admin', 'status[]=pending', 'page=-1', 'page[]=1'] as $query) $this->get('/cuenta/solicitudes/recibidas?'.$query)->assertStatus(422)->assertSee('Revisa los filtros');
    }

    public function test_unrelated_clients_providers_and_administrators_cannot_read_or_decide(): void
    {
        $inquiry = $this->inquiry();
        $before = $inquiry->getAttributes();
        foreach ([[RoleName::Client], [RoleName::Provider], [RoleName::Admin], [RoleName::Admin, RoleName::Client, RoleName::Provider]] as $roles) {
            $this->actingAs($this->account(...$roles), 'web')->getJson(route('inquiries.show', $inquiry))->assertForbidden();
            foreach (InquiryAction::cases() as $action) $this->patchJson(route('inquiries.'.$action->value, $inquiry))->assertForbidden();
        }
        $this->assertSame($before, $inquiry->fresh()->getAttributes());
    }

    public function test_client_and_provider_cannot_perform_each_others_actions(): void
    {
        $client = $this->account(RoleName::Client, RoleName::Provider);
        $provider = $this->account(RoleName::Client, RoleName::Provider);
        $inquiry = $this->inquiry($client, $provider);
        $this->actingAs($client, 'web');
        foreach (['accept', 'reject', 'complete'] as $action) $this->patch(route('inquiries.'.$action, $inquiry))->assertForbidden();
        $this->actingAs($provider, 'web')->patch(route('inquiries.cancel', $inquiry))->assertForbidden();
        $this->assertSame(InquiryStatus::Pending, $inquiry->fresh()->status);
    }

    public function test_explicit_transition_matrix_and_terminal_states_are_enforced_by_backend(): void
    {
        $client = $this->account(RoleName::Client);
        $provider = $this->account(RoleName::Provider);
        foreach (InquiryStatus::cases() as $source) {
            foreach (InquiryAction::cases() as $action) {
                $inquiry = $this->inquiry($client, $provider, $source);
                $before = $inquiry->getAttributes();
                $actor = $action === InquiryAction::Cancel ? $client : $provider;
                $response = $this->actingAs($actor, 'web')->patch(route('inquiries.'.$action->value, $inquiry));
                if ($source === $action->source()) {
                    $response->assertStatus(303)->assertSessionHas('success');
                    $this->assertSame($action->target(), $inquiry->fresh()->status);
                    $this->assertNotNull($inquiry->fresh()->{$action->timestampColumn()});
                } else {
                    $response->assertStatus(409)->assertSee('La solicitud ya cambió de estado');
                    $this->assertSame($before, $inquiry->fresh()->getAttributes());
                }
            }
        }
    }

    public function test_repeated_decisions_do_not_reset_timestamps_or_allow_opposing_decisions(): void
    {
        $provider = $this->account(RoleName::Provider);
        $inquiry = $this->inquiry(null, $provider);
        $this->actingAs($provider, 'web')->patch(route('inquiries.accept', $inquiry))->assertStatus(303);
        $before = $inquiry->fresh()->getAttributes();
        $this->travel(2)->minutes();
        $this->patchJson(route('inquiries.accept', $inquiry))->assertStatus(409)->assertJsonStructure(['message', 'inquiry_url']);
        $this->patch(route('inquiries.reject', $inquiry))->assertStatus(409);
        $this->assertSame($before, $inquiry->fresh()->getAttributes());
        $this->patch(route('inquiries.complete', $inquiry))->assertStatus(303);
        $done = $inquiry->fresh();
        $this->assertSame($before['accepted_at'], $done->getRawOriginal('accepted_at'));
        $this->assertCount(3, $done->timeline());
        $this->assertNotNull($done->completed_at);
        $this->get(route('inquiries.show', $inquiry))->assertOk()->assertSee('No confirma pago, garantía');
    }

    public function test_decision_forms_cannot_override_the_fixed_action_or_modify_ownership_content_and_roles(): void
    {
        $provider = $this->account(RoleName::Provider);
        $inquiry = $this->inquiry(null, $provider);
        $attack = ['status' => 'completed', 'decision' => 'complete', 'action' => 'complete', 'message' => 'Cambiar el mensaje',
            'client_user_id' => 999, 'provider_profile_id' => 999, 'roles' => ['admin'], 'accepted_at' => '2030-01-01'];
        $before = $inquiry->getAttributes();
        $this->actingAs($provider, 'web')->patchJson(route('inquiries.accept', $inquiry), $attack)->assertUnprocessable()->assertJsonValidationErrors(array_keys($attack));
        $this->patch(route('inquiries.show', $inquiry), ['status' => 'accepted'])->assertStatus(405);
        $this->assertSame($before, $inquiry->fresh()->getAttributes());
    }

    public function test_client_keeps_history_and_can_cancel_when_the_provider_role_has_been_revoked(): void
    {
        $client = $this->account(RoleName::Client);
        $provider = $this->account(RoleName::Provider);
        $inquiry = $this->inquiry($client, $provider);
        $provider->roles()->detach();
        $this->actingAs($client, 'web')->get(route('inquiries.show', $inquiry))->assertOk()->assertSee('Tu seguimiento se conserva');
        $this->post(route('inquiries.store', $inquiry->service_id), ['message' => 'No crear otra solicitud privada.'])->assertNotFound();
        $this->patch(route('inquiries.cancel', $inquiry))->assertStatus(303);
        $this->actingAs($provider, 'web')->get(route('inquiries.show', $inquiry))->assertForbidden();
        $this->get('/cuenta/solicitudes/recibidas')->assertForbidden();
        $this->assertSame(InquiryStatus::Cancelled, $inquiry->fresh()->status);
        $this->assertDatabaseCount('service_inquiries', 1);
    }

    public function test_history_survives_hidden_and_edited_services_without_exposing_private_new_content(): void
    {
        $client = $this->account(RoleName::Client);
        $provider = $this->account(RoleName::Provider);
        $inquiry = $this->inquiry($client, $provider);
        $inquiry->service->update(['status' => 'draft', 'title' => 'PRIVATE-NEW-TITLE', 'description' => 'PRIVATE-NEW-DESCRIPTION']);
        $this->actingAs($client, 'web')->get(route('inquiries.show', $inquiry))->assertOk()->assertSee($inquiry->service_title)
            ->assertSee('Tu seguimiento se conserva')->assertDontSee('PRIVATE-NEW-TITLE')->assertDontSee('PRIVATE-NEW-DESCRIPTION');
        $this->get('/cuenta/solicitudes')->assertOk()->assertDontSee('PRIVATE-NEW-TITLE');
        $this->post(route('inquiries.store', $inquiry->service_id), ['message' => 'No debe crearse una solicitud.'])->assertNotFound();
        $this->actingAs($provider, 'web')->get('/cuenta/solicitudes/recibidas')->assertOk()->assertSee($inquiry->service_title);
        $this->patch(route('inquiries.accept', $inquiry))->assertStatus(303);
        $this->assertDatabaseCount('service_inquiries', 1);
        $this->assertDatabaseCount('services', 1);
        $this->assertDatabaseCount('citas', 0);
    }

    public function test_current_roles_are_required_and_admin_does_not_substitute_for_them(): void
    {
        $client = $this->account(RoleName::Client, RoleName::Admin);
        $provider = $this->account(RoleName::Provider, RoleName::Admin);
        $inquiry = $this->inquiry($client, $provider);
        $client->roles()->detach($client->roles()->where('slug', 'client')->firstOrFail()->id);
        $this->actingAs($client, 'web')->get(route('inquiries.show', $inquiry))->assertForbidden();
        $this->patch(route('inquiries.cancel', $inquiry))->assertForbidden();
        $provider->roles()->detach($provider->roles()->where('slug', 'provider')->firstOrFail()->id);
        $this->actingAs($provider, 'web')->get('/cuenta/solicitudes/recibidas')->assertForbidden();
        $this->get(route('inquiries.show', $inquiry))->assertForbidden();
        $this->patch(route('inquiries.accept', $inquiry))->assertForbidden();
        $this->assertSame(InquiryStatus::Pending, $inquiry->fresh()->status);
    }

    public function test_private_messages_are_escaped_and_never_enter_public_catalog_or_provider_pages(): void
    {
        $client = $this->account(RoleName::Client);
        $inquiry = $this->inquiry($client);
        $inquiry->update(['message' => '<script>PRIVATE-INQUIRY-MESSAGE()</script>']);
        $this->actingAs($client, 'web')->get(route('inquiries.show', $inquiry))->assertOk()->assertSee('&lt;script&gt;PRIVATE-INQUIRY-MESSAGE()', false)
            ->assertDontSee('<script>PRIVATE-INQUIRY-MESSAGE()', false)->assertDontSee($client->email);
        foreach (['/servicios', '/servicios/'.$inquiry->service_id, '/prestadores', '/prestadores/'.$inquiry->service->provider_profile_id] as $path) {
            $this->get($path)->assertOk()->assertDontSee('PRIVATE-INQUIRY-MESSAGE')->assertDontSee($client->email)->assertDontSee($client->password);
        }
    }

    public function test_dual_role_navigation_separates_sent_and_received_without_creating_identities(): void
    {
        $dual = $this->account(RoleName::Client, RoleName::Provider);
        $sent = $this->inquiry($dual);
        $received = $this->inquiry(null, $dual);
        $this->actingAs($dual, 'web')->get('/cuenta/cliente')->assertOk()->assertSee('Mis solicitudes')->assertSee('Solicitudes recibidas');
        $this->assertSame([$sent->id], $this->get('/cuenta/solicitudes')->viewData('inquiries')->pluck('id')->all());
        $this->assertSame([$received->id], $this->get('/cuenta/solicitudes/recibidas')->viewData('inquiries')->pluck('id')->all());
        $this->get('/cuenta/prestador')->assertOk();
        $this->assertAuthenticatedAs($dual, 'web');
        $this->assertDatabaseCount('users', 3);
        $this->assertDatabaseCount('profesionistas', 0);
    }

    public function test_submission_rate_limit_returns_a_clear_response_without_creating_a_sixth_inquiry(): void
    {
        $client = $this->account(RoleName::Client);
        $provider = $this->account(RoleName::Provider);
        $this->actingAs($client, 'web');
        for ($i = 0; $i < 6; $i++) {
            $service = $this->service($provider);
            $response = $this->post(route('inquiries.store', $service), ['message' => 'Necesito ayuda con este equipo.']);
            if ($i < 5) $response->assertStatus(303);
            else $response->assertStatus(429)->assertHeader('Retry-After')->assertSee('Espera antes de volver a enviar');
        }
        $this->assertDatabaseCount('service_inquiries', 5);
    }

    public function test_legacy_guard_and_matching_email_do_not_authorize_inquiries_or_change_credentials(): void
    {
        $inquiry = $this->inquiry();
        $legacy = Profesionista::create(['nombre' => 'Anterior', 'email' => $inquiry->client->email, 'cedula' => 'LEGACY-3B',
            'password' => Hash::make('old-password'), 'google_access_token' => 'fake-old-token']);
        $before = $legacy->fresh()->getAttributes();
        $this->post('/login-profesionista', ['email' => $legacy->email, 'password' => 'old-password'])->assertRedirect('/dashboard-profesionista');
        $this->get(route('inquiries.show', $inquiry))->assertRedirect('/login');
        $this->patchJson(route('inquiries.accept', $inquiry))->assertUnauthorized();
        $this->get('/solicitudes-citas')->assertOk();
        $this->get('/profesionista/disponibilidad')->assertOk();
        $this->assertSame($before, $legacy->fresh()->getAttributes());
        $this->assertDatabaseCount('citas', 0);
        $this->assertGuest('web');
    }
}
