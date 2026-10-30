<?php

namespace Tests\Feature;

use App\Enums\InquiryAction;
use App\Enums\InquiryNotificationEvent;
use App\Enums\InquiryStatus;
use App\Enums\RoleName;
use App\Models\InquiryNotification;
use App\Models\Profesionista;
use App\Models\ServiceInquiry;
use App\Services\InquiryWorkflow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Tests\Support\InquiryFixtures;
use Tests\TestCase;

class InquiryNotificationsTest extends TestCase
{
    use InquiryFixtures;

    public function test_creation_notifies_only_the_central_provider_with_minimal_server_defined_data(): void
    {
        $client = $this->account(RoleName::Client);
        $provider = $this->account(RoleName::Provider);
        $service = $this->service($provider);
        $this->actingAs($client, 'web')->post(route('inquiries.store', $service), ['message' => 'PRIVATE-MESSAGE que no debe aparecer en el aviso.',
            'recipient_id' => $client->id, 'event' => 'completed', 'read_at' => '1990-01-01', 'url' => 'https://example.test/untrusted'])->assertStatus(303);
        $inquiry = ServiceInquiry::firstOrFail();
        $notice = InquiryNotification::sole();
        $this->assertSame($provider->id, $notice->user_id);
        $this->assertSame($inquiry->id, $notice->service_inquiry_id);
        $this->assertSame(InquiryNotificationEvent::Created, $notice->event);
        $this->assertNotNull($notice->created_at);
        $this->assertNull($notice->read_at);
        $this->assertSame($provider->id, $notice->user->id);
        $this->assertSame($inquiry->id, $notice->inquiry->id);
        $this->assertSame(0, $client->inquiryNotifications()->count());
        $this->assertSame(['id', 'user_id', 'service_inquiry_id', 'event', 'created_at', 'read_at'], array_keys($notice->getAttributes()));
        foreach ([$inquiry->message, $client->email, $provider->email, $client->password, $provider->password, $service->title] as $private) {
            $this->assertStringNotContainsString($private, $notice->toJson());
        }
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_acceptance_and_completion_notify_the_client_once_each_and_never_the_actor(): void
    {
        $client = $this->account(RoleName::Client);
        $provider = $this->account(RoleName::Provider);
        $inquiry = $this->inquiry($client, $provider);
        foreach (['accept' => InquiryNotificationEvent::Accepted, 'complete' => InquiryNotificationEvent::Completed] as $action => $event) {
            $this->actingAs($provider, 'web')->patch(route('inquiries.'.$action, $inquiry))->assertStatus(303);
            $this->assertDatabaseHas('inquiry_notifications', ['user_id' => $client->id, 'service_inquiry_id' => $inquiry->id, 'event' => $event->value, 'read_at' => null]);
            $this->patchJson(route('inquiries.'.$action, $inquiry))->assertStatus(409);
        }
        $this->assertSame(['accepted', 'completed'], $client->inquiryNotifications()->orderBy('id')->pluck('event')->map->value->all());
        $this->assertSame(['created'], $provider->inquiryNotifications()->pluck('event')->map->value->all());
        $this->assertDatabaseCount('inquiry_notifications', 3);
    }

    public function test_rejection_notifies_only_the_requesting_client(): void
    {
        $provider = $this->account(RoleName::Provider);
        $inquiry = $this->inquiry(null, $provider);
        $this->actingAs($provider, 'web')->patch(route('inquiries.reject', $inquiry))->assertStatus(303);
        $this->assertDatabaseHas('inquiry_notifications', ['user_id' => $inquiry->client_user_id, 'service_inquiry_id' => $inquiry->id, 'event' => 'rejected']);
        $this->assertSame(1, $provider->inquiryNotifications()->count());
        $this->assertDatabaseCount('inquiry_notifications', 2);
    }

    public function test_cancellation_notifies_the_provider_even_after_the_provider_role_is_revoked(): void
    {
        $client = $this->account(RoleName::Client);
        $provider = $this->account(RoleName::Provider);
        $inquiry = $this->inquiry($client, $provider);
        $provider->roles()->detach();
        $this->actingAs($client, 'web')->patch(route('inquiries.cancel', $inquiry))->assertStatus(303);
        $this->assertSame(['created', 'cancelled'], $provider->inquiryNotifications()->orderBy('id')->pluck('event')->map->value->all());
        $this->assertSame(0, $client->inquiryNotifications()->count());
        $this->actingAs($provider, 'web')->get(route('notifications.index'))->assertOk()->assertSee('Solicitud cancelada')
            ->assertDontSee('href="'.route('inquiries.show', $inquiry).'"', false)->assertSee('permisos actuales');
        $this->get(route('inquiries.show', $inquiry))->assertForbidden();
    }

    public function test_authorization_validation_and_conflicts_do_not_add_notifications(): void
    {
        $client = $this->account(RoleName::Client);
        $provider = $this->account(RoleName::Provider);
        $service = $this->service($provider);
        $this->actingAs($provider, 'web')->postJson(route('inquiries.store', $service), ['message' => 'Un prestador sin rol cliente.'])->assertForbidden();
        $this->actingAs($client, 'web')->postJson(route('inquiries.store', $service), ['message' => 'corto'])->assertUnprocessable();
        $this->postJson(route('inquiries.store', $service), ['message' => 'Solicitud con campos manipulados.', 'user_id' => $provider->id, 'event' => 'completed'])->assertUnprocessable();
        $this->assertDatabaseCount('inquiry_notifications', 0);
        $inquiry = app(InquiryWorkflow::class)->open($client, $service->id, 'Necesito revisar mi equipo de trabajo.')['inquiry'];
        $this->actingAs($this->account(RoleName::Admin), 'web')->patchJson(route('inquiries.accept', $inquiry))->assertForbidden();
        $this->actingAs($provider, 'web')->patchJson(route('inquiries.accept', $inquiry), ['status' => 'completed'])->assertUnprocessable();
        $this->patchJson(route('inquiries.complete', $inquiry))->assertStatus(409);
        $this->assertDatabaseCount('inquiry_notifications', 1);
        $this->assertSame(InquiryStatus::Pending, $inquiry->fresh()->status);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_resubmission_while_pending_or_accepted_does_not_notify_again_but_a_new_inquiry_after_closure_does(): void
    {
        $client = $this->account(RoleName::Client);
        $provider = $this->account(RoleName::Provider);
        $inquiry = $this->inquiry($client, $provider);
        foreach ([InquiryStatus::Pending, InquiryStatus::Accepted] as $status) {
            $before = InquiryNotification::count();
            $this->actingAs($client, 'web')->post(route('inquiries.store', $inquiry->service_id), ['message' => 'Reenvío sin cambiar el mensaje.'])->assertStatus(303);
            $this->assertDatabaseCount('inquiry_notifications', $before);
            if ($status === InquiryStatus::Pending) app(InquiryWorkflow::class)->decide($provider, $inquiry->id, InquiryAction::Accept);
        }
        app(InquiryWorkflow::class)->decide($provider, $inquiry->id, InquiryAction::Complete);
        $new = app(InquiryWorkflow::class)->open($client, $inquiry->service_id, 'Una nueva necesidad después del cierre.')['inquiry'];
        $this->assertNotSame($inquiry->id, $new->id);
        $this->assertSame(2, $provider->inquiryNotifications()->where('event', 'created')->count());
        $this->assertDatabaseCount('inquiry_notifications', 4);
    }

    public function test_a_notification_write_failure_rolls_back_both_creation_and_transition(): void
    {
        $client = $this->account(RoleName::Client);
        $provider = $this->account(RoleName::Provider);
        $service = $this->service($provider);
        $workflow = app(InquiryWorkflow::class);
        $event = 'eloquent.creating: '.InquiryNotification::class;
        $rejectWrite = fn () => throw new \RuntimeException('Simulated notification storage failure');
        Event::listen($event, $rejectWrite);
        try {
            $workflow->open($client, $service->id, 'Una creación que debe revertirse por completo.');
            $this->fail('El error de persistencia debe propagarse.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated notification storage failure', $exception->getMessage());
        } finally {
            Event::forget($event);
        }
        $this->assertDatabaseCount('service_inquiries', 0);
        $this->assertDatabaseCount('inquiry_notifications', 0);
        $inquiry = $workflow->open($client, $service->id, 'Creación que sí puede guardarse correctamente.')['inquiry'];
        $before = $inquiry->fresh()->getAttributes();
        Event::listen($event, $rejectWrite);
        try {
            $workflow->decide($provider, $inquiry->id, InquiryAction::Accept);
            $this->fail('La aceptación debe revertirse si falla su aviso.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated notification storage failure', $exception->getMessage());
        } finally {
            Event::forget($event);
        }
        $this->assertSame($before, $inquiry->fresh()->getAttributes());
        $this->assertDatabaseCount('inquiry_notifications', 1);
    }

    public function test_an_outer_transaction_rollback_leaves_neither_inquiry_nor_notifications_and_preserves_previous_history(): void
    {
        $client = $this->account(RoleName::Client);
        $provider = $this->account(RoleName::Provider);
        $service = $this->service($provider);
        $workflow = app(InquiryWorkflow::class);
        DB::beginTransaction();
        $workflow->open($client, $service->id, 'Este envío se revierte junto con su aviso.');
        $this->assertDatabaseCount('inquiry_notifications', 1);
        DB::rollBack();
        $this->assertDatabaseCount('service_inquiries', 0);
        $this->assertDatabaseCount('inquiry_notifications', 0);
        $inquiry = $workflow->open($client, $service->id, 'Esta solicitud debe conservarse pendiente.')['inquiry'];
        $before = $inquiry->fresh()->getAttributes();
        DB::beginTransaction();
        $workflow->decide($provider, $inquiry->id, InquiryAction::Accept);
        $this->assertDatabaseCount('inquiry_notifications', 2);
        DB::rollBack();
        $this->assertSame($before, $inquiry->fresh()->getAttributes());
        $this->assertDatabaseCount('inquiry_notifications', 1);
    }

    public function test_listing_is_private_paginated_and_orders_by_creation_date_then_id_without_marking_as_read(): void
    {
        $provider = $this->account(RoleName::Provider);
        for ($i = 0; $i < 13; $i++) $this->inquiry(null, $provider);
        $foreign = $this->inquiry();
        $oldestId = $provider->inquiryNotifications()->min('id');
        DB::table('inquiry_notifications')->where('id', $oldestId)->update(['created_at' => now()->addDay()]);
        $expected = $provider->inquiryNotifications()->orderByDesc('created_at')->orderByDesc('id')->pluck('id')->all();
        $response = $this->actingAs($provider, 'web')->get(route('notifications.index'))->assertOk()->assertSee('Página 1 de 2')->assertSee('13 sin leer');
        $list = $response->viewData('notifications');
        $this->assertSame(13, $list->total());
        $this->assertSame(array_slice($expected, 0, 12), $list->pluck('id')->all());
        $this->assertSame(array_slice($expected, 12), $this->get(route('notifications.index', ['page' => 2]))->viewData('notifications')->pluck('id')->all());
        $this->assertSame(13, $provider->inquiryNotifications()->unread()->count());
        $this->assertNotContains($foreign->id, $list->pluck('service_inquiry_id')->all());
        foreach (['page=-1', 'page[]=1', 'page=abc'] as $query) $this->getJson('/cuenta/notificaciones?'.$query)->assertUnprocessable();
    }

    public function test_users_and_admin_cannot_mark_foreign_or_missing_notifications(): void
    {
        $inquiry = $this->inquiry();
        $notice = InquiryNotification::sole();
        foreach ([RoleName::Client, RoleName::Provider, RoleName::Admin] as $role) {
            $this->actingAs($this->account($role), 'web')->get(route('notifications.index'))->assertOk()->assertSee('Aún no tienes notificaciones')
                ->assertDontSee('href="'.route('inquiries.show', $inquiry).'"', false);
            $this->post(route('notifications.read', $notice))->assertNotFound();
            $this->post(route('notifications.read', '999999999999999999999999999999999'))->assertNotFound();
            $this->get(route('inquiries.show', $inquiry))->assertForbidden();
        }
        $this->assertNull($notice->fresh()->read_at);
        // No existe un endpoint público de detalle de avisos ni de escritura mediante GET.
        $this->get('/cuenta/notificaciones/'.$notice->id)->assertNotFound();
        $this->get(route('notifications.read', $notice))->assertStatus(405);
    }

    public function test_read_is_atomic_idempotent_and_ignores_browser_supplied_identity_event_dates_and_urls(): void
    {
        $provider = $this->account(RoleName::Provider);
        $inquiry = $this->inquiry(null, $provider);
        $notice = InquiryNotification::sole();
        $before = $notice->getAttributes();
        $this->actingAs($provider, 'web')->get(route('account.provider'))->assertOk()->assertSee('1 sin leer');
        $this->post(route('notifications.read', $notice), ['user_id' => 999, 'service_inquiry_id' => 999, 'event' => 'completed',
            'read_at' => '1990-01-01', 'created_at' => '1990-01-01', 'url' => 'https://example.test/steal', 'roles' => ['admin']])->assertRedirect(route('notifications.index'))->assertStatus(303);
        $readAt = $notice->fresh()->getRawOriginal('read_at');
        $this->assertNotNull($readAt);
        $this->assertNotSame('1990-01-01', $readAt);
        foreach ($before as $key => $value) if ($key !== 'read_at') $this->assertSame($value, $notice->getRawOriginal($key));
        $this->travel(10)->minutes();
        $this->post(route('notifications.read', $notice))->assertStatus(303);
        $this->assertSame($readAt, $notice->fresh()->getRawOriginal('read_at'));
        $this->get(route('notifications.index'))->assertOk()->assertSee('Leída el')->assertDontSee('Marcar como leída')->assertDontSee('1 sin leer');
        $this->assertSame(0, $provider->inquiryNotifications()->unread()->count());
        $this->assertFalse($provider->hasRole(RoleName::Admin));
        $this->assertSame(InquiryStatus::Pending, $inquiry->fresh()->status);
    }

    public function test_dual_account_receives_both_experiences_in_one_inbox_and_count_is_scoped_to_current_user(): void
    {
        $dual = $this->account(RoleName::Client, RoleName::Provider);
        $otherProvider = $this->account(RoleName::Provider);
        $sent = $this->inquiry($dual, $otherProvider);
        $received = $this->inquiry(null, $dual);
        app(InquiryWorkflow::class)->decide($otherProvider, $sent->id, InquiryAction::Accept);
        $this->assertSame(2, $dual->inquiryNotifications()->unread()->count());
        $this->actingAs($dual, 'web')->get(route('notifications.index'))->assertOk()->assertSee('2 sin leer')->assertSee('Nueva solicitud')->assertSee('Solicitud aceptada')
            ->assertSee('href="'.route('inquiries.show', $sent).'"', false)->assertSee('href="'.route('inquiries.show', $received).'"', false);
        $this->assertAuthenticatedAs($dual, 'web');
        $this->assertDatabaseCount('users', 3);
        $this->assertDatabaseCount('profesionistas', 0);
        $this->assertDatabaseCount('inquiry_notifications', 3);
    }

    public function test_revoked_client_role_keeps_notice_but_hides_link_and_cannot_bypass_inquiry_policy(): void
    {
        $client = $this->account(RoleName::Client, RoleName::Admin);
        $provider = $this->account(RoleName::Provider);
        $inquiry = $this->inquiry($client, $provider, InquiryStatus::Accepted);
        $client->roles()->detach($client->roles()->where('slug', 'client')->value('id'));
        $notice = $client->inquiryNotifications()->sole();
        $this->actingAs($client, 'web')->get(route('notifications.index'))->assertOk()->assertSee('Solicitud aceptada')->assertSee('permisos actuales')
            ->assertDontSee('href="'.route('inquiries.show', $inquiry).'"', false);
        $this->get(route('inquiries.show', $inquiry))->assertForbidden();
        $this->post(route('notifications.read', $notice))->assertStatus(303);
        $this->assertNotNull($notice->fresh()->read_at);
    }

    public function test_pausing_and_editing_a_service_preserves_notifications_and_authorized_private_links(): void
    {
        $provider = $this->account(RoleName::Provider);
        $client = $this->account(RoleName::Client);
        $inquiry = $this->inquiry($client, $provider);
        $before = InquiryNotification::sole()->getAttributes();
        $inquiry->service->update(['status' => 'paused', 'title' => 'PRIVATE-EDITED-TITLE']);
        $this->actingAs($provider, 'web')->get(route('notifications.index'))->assertOk()->assertSee('href="'.route('inquiries.show', $inquiry).'"', false)->assertDontSee('PRIVATE-EDITED-TITLE');
        $this->get(route('inquiries.show', $inquiry))->assertOk();
        $this->assertSame($before, InquiryNotification::sole()->getAttributes());
        $this->patch(route('inquiries.accept', $inquiry))->assertStatus(303);
        $this->actingAs($client, 'web')->get(route('notifications.index'))->assertOk()->assertSee('href="'.route('inquiries.show', $inquiry).'"', false);
        $this->get(route('inquiries.show', $inquiry))->assertOk();
        $this->assertDatabaseCount('inquiry_notifications', 2);
    }

    public function test_notification_html_contains_no_private_payload_and_blade_escapes_notification_text(): void
    {
        $client = $this->account(RoleName::Client);
        $provider = $this->account(RoleName::Provider);
        $inquiry = $this->inquiry($client, $provider);
        $inquiry->update(['message' => '<script>PRIVATE-MESSAGE()</script>']);
        $inquiry->service->update(['title' => '<script>PRIVATE-TITLE()</script>']);
        $this->actingAs($provider, 'web')->get(route('notifications.index'))->assertOk()->assertDontSee('PRIVATE-MESSAGE')->assertDontSee('PRIVATE-TITLE')
            ->assertDontSee($client->email)->assertDontSee($provider->email)->assertDontSee($client->password)->assertDontSee($provider->password);
        // Los títulos reales son constantes del enum; comprobar también el contrato de escape de la vista con un texto hostil.
        $notice = InquiryNotification::sole();
        $notice->setRelation('inquiry', $inquiry);
        $fake = (object) ['id' => $notice->id, 'service_inquiry_id' => $inquiry->id, 'inquiry' => $inquiry,
            'created_at' => now(), 'read_at' => now(), 'event' => new class {
                public function label() { return '<script>BAD-EVENT()</script>'; }
                public function title() { return '<img src=x onerror=BAD-TITLE()>'; }
            }];
        $paginator = new \Illuminate\Pagination\LengthAwarePaginator([$fake], 1, 12);
        $html = view('notifications.index', ['notifications' => $paginator])->render();
        $this->assertStringContainsString('&lt;script&gt;BAD-EVENT()', $html);
        $this->assertStringContainsString('&lt;img src=x onerror=BAD-TITLE()&gt;', $html);
        $this->assertStringNotContainsString('<script>BAD-EVENT()', $html);
        $this->assertStringNotContainsString('<img src=x onerror=BAD-TITLE()>', $html);
    }

    public function test_legacy_guard_and_matching_email_do_not_expose_central_notices_or_change_legacy_data(): void
    {
        $provider = $this->account(RoleName::Provider);
        $this->inquiry(null, $provider);
        $legacy = Profesionista::create(['nombre' => 'Profesional anterior', 'email' => $provider->email, 'cedula' => 'NOTICE-LEGACY',
            'password' => Hash::make('old-password'), 'google_access_token' => 'fake-preserved-token']);
        $before = $legacy->fresh()->getAttributes();
        $this->post('/login-profesionista', ['email' => $legacy->email, 'password' => 'old-password'])->assertRedirect('/dashboard-profesionista');
        $this->get(route('notifications.index'))->assertRedirect('/login');
        $this->postJson(route('notifications.read', InquiryNotification::sole()))->assertUnauthorized();
        $this->get('/solicitudes-citas')->assertOk()->assertDontSee('/cuenta/notificaciones');
        $this->get('/profesionista/disponibilidad')->assertOk();
        $this->assertSame($before, $legacy->fresh()->getAttributes());
        $this->assertDatabaseCount('citas', 0);
        $this->assertDatabaseCount('inquiry_notifications', 1);
        $this->assertGuest('web');
    }

    public function test_guests_cannot_list_or_mark_notices_and_accounts_without_roles_can_use_their_empty_inbox(): void
    {
        $this->get(route('notifications.index'))->assertRedirect('/login');
        $this->post(route('notifications.read', 1))->assertRedirect('/login');
        $this->getJson(route('notifications.index'))->assertUnauthorized();
        $this->postJson(route('notifications.read', 1))->assertUnauthorized();
        $this->actingAs($this->account(), 'web')->get(route('notifications.index'))->assertOk()->assertSee('Aún no tienes notificaciones');
        $this->assertDatabaseCount('inquiry_notifications', 0);
    }
}
