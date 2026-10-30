<?php

namespace Tests\Feature;

use App\Enums\InquiryAction;
use App\Enums\InquiryStatus;
use App\Enums\RoleName;
use App\Exceptions\InquiryMessageConflict;
use App\Models\InquiryNotification;
use App\Models\Profesionista;
use App\Models\ServiceInquiryMessage;
use App\Services\InquiryMessaging;
use App\Services\InquiryWorkflow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\Support\MessageFixtures;
use Tests\TestCase;

class InquiryMessagesTest extends TestCase
{
    use MessageFixtures;

    public function test_client_and_provider_converse_in_pending_and_accepted_without_modifying_the_initial_need_or_notifications(): void
    {
        $client = $this->account(RoleName::Client, RoleName::Provider);
        $provider = $this->account(RoleName::Client, RoleName::Provider);
        $inquiry = $this->inquiry($client, $provider);
        $before = $inquiry->getAttributes();
        $notices = InquiryNotification::all()->toArray();
        $this->actingAs($client, 'web')->get(route('inquiries.show', $inquiry))->assertOk()->assertSee('Todavía no hay mensajes')->assertSee('Necesidad del cliente');
        $this->post(route('inquiries.messages.store', $inquiry), $this->messageData('¿Cuándo podríamos revisar el equipo?'))->assertStatus(303)
            ->assertRedirect(route('inquiries.show', $inquiry).'#conversacion')->assertSessionHas('success');
        $this->actingAs($provider, 'web')->post(route('inquiries.messages.store', $inquiry), $this->messageData('Puedo orientarte con el diagnóstico.'))->assertStatus(303);
        $this->assertSame($before, $inquiry->fresh()->getAttributes());
        $this->assertSame($notices, InquiryNotification::all()->toArray());
        $this->assertSame([$client->id, $provider->id], $inquiry->messages()->orderBy('id')->pluck('author_user_id')->all());
        $message = $inquiry->messages()->firstOrFail();
        $this->assertSame($client->id, $message->author->id);
        $this->assertSame($inquiry->id, $message->inquiry->id);
        $this->patch(route('inquiries.accept', $inquiry))->assertStatus(303);
        $this->post(route('inquiries.messages.store', $inquiry), $this->messageData('Ahora que está aceptada, continuamos aquí.'))->assertStatus(303);
        $this->actingAs($client, 'web')->post(route('inquiries.messages.store', $inquiry), $this->messageData('De acuerdo, gracias.'))->assertStatus(303);
        $this->get(route('inquiries.show', $inquiry))->assertOk()->assertSeeInOrder(['¿Cuándo podríamos revisar el equipo?', 'Puedo orientarte con el diagnóstico.', 'Ahora que está aceptada, continuamos aquí.', 'De acuerdo, gracias.'])
            ->assertSee('Cliente · '.$client->name)->assertSee('Prestador · '.$provider->name)->assertSee('(tú)');
        $this->assertDatabaseCount('service_inquiry_messages', 4);
        $this->assertDatabaseCount('inquiry_notifications', 2);
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('citas', 0);
    }

    public function test_all_terminal_states_keep_history_read_only_and_reject_new_messages_from_both_participants(): void
    {
        foreach ([InquiryStatus::Rejected, InquiryStatus::Cancelled, InquiryStatus::Completed] as $status) {
            $client = $this->account(RoleName::Client);
            $provider = $this->account(RoleName::Provider);
            $inquiry = $this->inquiry($client, $provider);
            $this->message($client, $inquiry, 'HISTORIAL-CONSERVADO-'.$status->value);
            $workflow = app(InquiryWorkflow::class);
            if ($status === InquiryStatus::Completed) $workflow->decide($provider, $inquiry->id, InquiryAction::Accept);
            $action = match ($status) { InquiryStatus::Rejected => InquiryAction::Reject, InquiryStatus::Cancelled => InquiryAction::Cancel, InquiryStatus::Completed => InquiryAction::Complete };
            $workflow->decide($status === InquiryStatus::Cancelled ? $client : $provider, $inquiry->id, $action);
            $before = $inquiry->fresh()->getAttributes();
            $notices = InquiryNotification::count();
            foreach ([$client, $provider] as $actor) {
                $this->actingAs($actor, 'web')->get(route('inquiries.show', $inquiry))->assertOk()->assertSee('HISTORIAL-CONSERVADO-'.$status->value)
                    ->assertSee('modo solo lectura')->assertDontSee('name="body"', false);
                $this->postJson(route('inquiries.messages.store', $inquiry), $this->messageData())->assertStatus(409);
            }
            $this->assertSame(1, $inquiry->messages()->count());
            $this->assertSame($before, $inquiry->fresh()->getAttributes());
            $this->assertDatabaseCount('inquiry_notifications', $notices);
        }
    }

    public function test_third_parties_and_admins_cannot_read_or_send_even_with_all_business_roles(): void
    {
        $client = $this->account(RoleName::Client);
        $inquiry = $this->inquiry($client);
        $this->message($client, $inquiry, 'PRIVATE-CONVERSATION-MARKER');
        foreach ([[RoleName::Client], [RoleName::Provider], [RoleName::Admin], [RoleName::Admin, RoleName::Client, RoleName::Provider], []] as $roles) {
            $this->actingAs($this->account(...$roles), 'web')->get(route('inquiries.show', $inquiry))->assertForbidden()->assertDontSee('PRIVATE-CONVERSATION-MARKER');
            $this->postJson(route('inquiries.messages.store', $inquiry), $this->messageData())->assertForbidden();
        }
        $this->assertDatabaseCount('service_inquiry_messages', 1);
    }

    public function test_revoking_the_required_role_blocks_reading_writing_and_replaying_own_messages(): void
    {
        $client = $this->account(RoleName::Client, RoleName::Provider, RoleName::Admin);
        $provider = $this->account(RoleName::Client, RoleName::Provider, RoleName::Admin);
        $inquiry = $this->inquiry($client, $provider);
        foreach ([[$client, 'client'], [$provider, 'provider']] as [$actor, $role]) {
            $payload = $this->messageData();
            $this->message($actor, $inquiry, $payload['body'], $payload['idempotency_key']);
            $actor->roles()->detach($actor->roles()->where('slug', $role)->value('id'));
            $this->actingAs($actor, 'web')->get(route('inquiries.show', $inquiry))->assertForbidden();
            $this->postJson(route('inquiries.messages.store', $inquiry), $payload)->assertForbidden();
            $this->postJson(route('inquiries.messages.store', $inquiry), $this->messageData())->assertForbidden();
        }
        $this->assertDatabaseCount('service_inquiry_messages', 2);
    }

    public function test_payload_cannot_override_author_destination_roles_dates_or_add_attachments(): void
    {
        $client = $this->account(RoleName::Client);
        $inquiry = $this->inquiry($client);
        $other = $this->inquiry();
        $attack = ['author_user_id' => $other->client_user_id, 'sender_id' => 999, 'recipient_id' => 999,
            'inquiry_id' => $other->id, 'service_inquiry_id' => $other->id, 'service_id' => $other->service_id,
            'client_user_id' => 999, 'user_id' => 999, 'roles' => ['admin'], 'status' => 'completed', 'created_at' => '1990-01-01',
            'updated_at' => '1990-01-01', 'attachments' => ['file.pdf'], 'event' => 'created', 'read_at' => '1990-01-01'];
        $this->actingAs($client, 'web')->postJson(route('inquiries.messages.store', $inquiry), $this->messageData() + $attack)
            ->assertUnprocessable()->assertJsonValidationErrors(array_keys($attack));
        $this->postJson(route('inquiries.messages.store', $other), $this->messageData())->assertForbidden();
        $this->assertDatabaseCount('service_inquiry_messages', 0);
        $this->assertFalse($client->hasRole(RoleName::Admin));
        $this->expectException(\Illuminate\Database\Eloquent\MassAssignmentException::class);
        new ServiceInquiryMessage($attack + $this->messageData());
    }

    public function test_body_validation_rejects_empty_arrays_and_overlong_unicode_and_normalizes_newlines(): void
    {
        $client = $this->account(RoleName::Client);
        $inquiry = $this->inquiry($client);
        $this->actingAs($client, 'web');
        foreach (['', '   ', [], str_repeat('ñ', 2001)] as $body) {
            $payload = $this->messageData(); $payload['body'] = $body;
            $this->postJson(route('inquiries.messages.store', $inquiry), $payload)->assertUnprocessable()->assertJsonValidationErrors('body');
        }
        $this->post(route('inquiries.messages.store', $inquiry), $this->messageData("  Línea uno\r\nLínea dos\rLínea tres  "))->assertStatus(303);
        $this->assertSame("Línea uno\nLínea dos\nLínea tres", ServiceInquiryMessage::sole()->body);
        $this->post(route('inquiries.messages.store', $inquiry), $this->messageData(str_repeat('ñ', 2000)))->assertStatus(303);
        $this->assertDatabaseCount('service_inquiry_messages', 2);
    }

    public function test_valid_idempotency_keys_are_required_and_validation_keeps_the_same_key_for_retry(): void
    {
        $client = $this->account(RoleName::Client);
        $inquiry = $this->inquiry($client);
        $this->actingAs($client, 'web');
        foreach ([null, 'bad', ['not-a-string']] as $key) {
            $this->postJson(route('inquiries.messages.store', $inquiry), ['body' => 'Texto válido', 'idempotency_key' => $key])->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
        }
        $payload = $this->messageData(str_repeat('a', 2001));
        $this->from(route('inquiries.show', $inquiry))->post(route('inquiries.messages.store', $inquiry), $payload)->assertRedirect(route('inquiries.show', $inquiry))->assertSessionHasErrors('body');
        $this->get(route('inquiries.show', $inquiry))->assertOk()->assertSee('value="'.$payload['idempotency_key'].'"', false);
        $this->assertDatabaseCount('service_inquiry_messages', 0);
    }

    public function test_retries_are_idempotent_case_insensitive_and_conflicting_reuse_never_overwrites_text(): void
    {
        $client = $this->account(RoleName::Client);
        $provider = $this->account(RoleName::Provider);
        $inquiry = $this->inquiry($client, $provider);
        $key = (string) Str::uuid(); $payload = $this->messageData('El texto original se conserva.', $key);
        $this->actingAs($client, 'web')->post(route('inquiries.messages.store', $inquiry), $payload)->assertStatus(303);
        $before = ServiceInquiryMessage::sole()->getAttributes();
        $this->travel(2)->minutes();
        $this->post(route('inquiries.messages.store', $inquiry), ['body' => $payload['body'], 'idempotency_key' => strtoupper($key)])->assertStatus(303)->assertSessionHas('info');
        $this->postJson(route('inquiries.messages.store', $inquiry), $this->messageData('No sobrescribir el texto anterior.', $key))->assertStatus(409);
        $this->assertSame($before, ServiceInquiryMessage::sole()->getAttributes());
        $this->post(route('inquiries.messages.store', $inquiry), $this->messageData($payload['body']))->assertStatus(303);
        $this->actingAs($provider, 'web')->post(route('inquiries.messages.store', $inquiry), $this->messageData('Otro autor puede usar su propia clave.', $key))->assertStatus(303);
        $other = $this->inquiry($client, $provider);
        $this->actingAs($client, 'web')->post(route('inquiries.messages.store', $other), $payload)->assertStatus(303);
        $this->assertDatabaseCount('service_inquiry_messages', 4);
    }

    public function test_replaying_a_successful_request_after_closure_returns_only_the_original(): void
    {
        $client = $this->account(RoleName::Client);
        $inquiry = $this->inquiry($client);
        $payload = $this->messageData();
        $this->actingAs($client, 'web')->post(route('inquiries.messages.store', $inquiry), $payload)->assertStatus(303);
        $this->patch(route('inquiries.cancel', $inquiry))->assertStatus(303);
        $this->post(route('inquiries.messages.store', $inquiry), $payload)->assertStatus(303)->assertSessionHas('info');
        $this->postJson(route('inquiries.messages.store', $inquiry), $this->messageData())->assertStatus(409);
        $this->assertDatabaseCount('service_inquiry_messages', 1);
        $this->assertSame(InquiryStatus::Cancelled, $inquiry->fresh()->status);
    }

    public function test_paused_and_edited_services_keep_the_conversation_and_accept_messages_for_active_inquiries(): void
    {
        $client = $this->account(RoleName::Client);
        $provider = $this->account(RoleName::Provider);
        $inquiry = $this->inquiry($client, $provider);
        $this->message($client, $inquiry, 'HISTORIAL-ANTES-DE-PAUSAR');
        $inquiry->service->update(['status' => 'paused', 'title' => 'NEW-PRIVATE-TITLE', 'description' => 'NEW-PRIVATE-DESCRIPTION']);
        $this->actingAs($provider, 'web')->get(route('inquiries.show', $inquiry))->assertOk()->assertSee('HISTORIAL-ANTES-DE-PAUSAR')->assertDontSee('NEW-PRIVATE-TITLE');
        $this->post(route('inquiries.messages.store', $inquiry), $this->messageData('La pausa no cierra la solicitud.'))->assertStatus(303);
        $this->assertDatabaseCount('service_inquiry_messages', 2);
    }

    public function test_html_is_plain_escaped_text_and_never_enters_public_pages_or_notice_payloads(): void
    {
        $client = $this->account(RoleName::Client);
        $provider = $this->account(RoleName::Provider);
        $inquiry = $this->inquiry($client, $provider);
        $body = '</textarea><script>PRIVATE-MESSAGE-XSS()</script><img src=x onerror=alert(1)>';
        $this->actingAs($client, 'web')->post(route('inquiries.messages.store', $inquiry), $this->messageData($body))->assertStatus(303);
        $this->actingAs($provider, 'web')->get(route('inquiries.show', $inquiry))->assertOk()->assertSee(e($body), false)->assertDontSee($body, false)->assertDontSee($client->email);
        foreach (['/servicios', '/servicios/'.$inquiry->service_id, '/prestadores/'.$inquiry->service->provider_profile_id, '/cuenta/notificaciones'] as $path) {
            $this->get($path)->assertOk()->assertDontSee('PRIVATE-MESSAGE-XSS')->assertDontSee($client->email)->assertDontSee($client->password);
        }
        $notice = InquiryNotification::sole();
        $this->assertStringNotContainsString('PRIVATE-MESSAGE-XSS', $notice->toJson());
        $message = ServiceInquiryMessage::sole()->load(['author', 'inquiry']);
        $this->assertArrayNotHasKey('author', $message->toArray());
        $this->assertArrayNotHasKey('inquiry', $message->toArray());
        $this->assertArrayNotHasKey('idempotency_key', $message->toArray());
    }

    public function test_storage_errors_return_a_safe_failure_without_logging_body_and_the_same_request_can_retry(): void
    {
        $client = $this->account(RoleName::Client);
        $inquiry = $this->inquiry($client);
        $before = $inquiry->getAttributes();
        $payload = $this->messageData('PRIVATE-SQL-BINDING-MARKER');
        DB::unprepared("CREATE TRIGGER reject_message BEFORE INSERT ON service_inquiry_messages BEGIN SELECT RAISE(ABORT, 'Simulated storage failure'); END");
        Log::spy();
        $this->actingAs($client, 'web')->postJson(route('inquiries.messages.store', $inquiry), $payload)->assertStatus(503)
            ->assertDontSee($payload['body'])->assertDontSee($payload['idempotency_key'])->assertDontSee('insert into');
        Log::shouldNotHaveReceived('error');
        Log::shouldNotHaveReceived('warning');
        $this->assertDatabaseCount('service_inquiry_messages', 0);
        $this->assertSame($before, $inquiry->fresh()->getAttributes());
        $this->assertDatabaseCount('inquiry_notifications', 1);
        DB::unprepared('DROP TRIGGER reject_message');
        $this->post(route('inquiries.messages.store', $inquiry), $payload)->assertStatus(303);
        $this->assertDatabaseCount('service_inquiry_messages', 1);
    }

    public function test_outer_rollback_removes_the_message_and_does_not_consume_its_idempotency_key(): void
    {
        $client = $this->account(RoleName::Client);
        $inquiry = $this->inquiry($client);
        $before = $inquiry->getAttributes(); $key = (string) Str::uuid();
        DB::beginTransaction();
        $this->message($client, $inquiry, 'Mensaje que todavía no está confirmado.', $key);
        $this->assertDatabaseCount('service_inquiry_messages', 1);
        DB::rollBack();
        $this->assertDatabaseCount('service_inquiry_messages', 0);
        $result = app(InquiryMessaging::class)->send($client, $inquiry->id, 'Mensaje que todavía no está confirmado.', $key);
        $this->assertTrue($result['created']);
        $this->assertSame($before, $inquiry->fresh()->getAttributes());
        $this->assertDatabaseCount('inquiry_notifications', 1);
    }

    public function test_nested_database_concurrency_errors_do_not_propagate_private_sql_or_exception_chains(): void
    {
        $client = $this->account(RoleName::Client);
        $inquiry = $this->inquiry($client);
        DB::unprepared("CREATE TRIGGER locked_message BEFORE INSERT ON service_inquiry_messages BEGIN SELECT RAISE(ABORT, 'database table is locked'); END");
        DB::beginTransaction();
        try {
            $this->message($client, $inquiry, 'PRIVATE-NESTED-SQL-MARKER');
            $this->fail('El fallo de base debe convertirse en un conflicto seguro.');
        } catch (InquiryMessageConflict $exception) {
            $this->assertNull($exception->getPrevious());
            $this->assertStringNotContainsString('PRIVATE-NESTED-SQL-MARKER', $exception->getMessage());
            $this->assertInstanceOf(\Illuminate\Contracts\Debug\ShouldntReport::class, $exception);
        } finally {
            DB::rollBack();
        }
        $this->assertDatabaseCount('service_inquiry_messages', 0);
        $this->assertDatabaseCount('inquiry_notifications', 1);
    }

    public function test_rate_limit_rejects_an_eleventh_message_without_writing_or_notifying(): void
    {
        $client = $this->account(RoleName::Client);
        $inquiry = $this->inquiry($client);
        $this->actingAs($client, 'web');
        for ($i = 0; $i < 10; $i++) $this->post(route('inquiries.messages.store', $inquiry), $this->messageData('Mensaje '.$i))->assertStatus(303);
        $this->post(route('inquiries.messages.store', $inquiry), $this->messageData('Mensaje limitado.'))->assertStatus(429)->assertHeader('Retry-After')->assertSee('Espera antes de volver');
        $this->assertDatabaseCount('service_inquiry_messages', 10);
        $this->assertDatabaseCount('inquiry_notifications', 1);
    }

    public function test_guests_and_matching_email_legacy_professionals_cannot_read_or_send_and_legacy_data_is_unchanged(): void
    {
        $client = $this->account(RoleName::Client);
        $inquiry = $this->inquiry($client);
        $this->message($client, $inquiry, 'PRIVATE-CENTRAL-MESSAGE');
        $this->get(route('inquiries.show', $inquiry))->assertRedirect('/login');
        $this->postJson(route('inquiries.messages.store', $inquiry), $this->messageData())->assertUnauthorized();
        $legacy = Profesionista::create(['nombre' => 'Profesional anterior', 'email' => $client->email, 'cedula' => 'LEGACY-5A',
            'password' => Hash::make('old-password'), 'google_access_token' => 'fake-preserved-access']);
        $before = $legacy->fresh()->getAttributes();
        session()->forget('url.intended');
        $this->post('/login-profesionista', ['email' => $legacy->email, 'password' => 'old-password'])->assertRedirect('/dashboard-profesionista');
        $this->get(route('inquiries.show', $inquiry))->assertRedirect('/login');
        $this->postJson(route('inquiries.messages.store', $inquiry), $this->messageData())->assertUnauthorized();
        $this->get('/solicitudes-citas')->assertOk()->assertDontSee('PRIVATE-CENTRAL-MESSAGE');
        $this->get('/profesionista/disponibilidad')->assertOk();
        $this->assertSame($before, $legacy->fresh()->getAttributes());
        $this->assertDatabaseCount('service_inquiry_messages', 1);
        $this->assertDatabaseCount('citas', 0);
        $this->assertGuest('web');
    }
}
