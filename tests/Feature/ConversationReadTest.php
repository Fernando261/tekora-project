<?php

namespace Tests\Feature;

use App\Enums\InquiryStatus;
use App\Enums\RoleName;
use App\Models\InquiryNotification;
use App\Models\InquiryReadProgress;
use App\Models\Profesionista;
use App\Services\InquiryReadTracking;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Tests\Support\ReadFixtures;
use Tests\TestCase;

class ConversationReadTest extends TestCase
{
    use ReadFixtures;

    public function test_incoming_counts_and_read_progress_are_individual_and_never_include_own_messages(): void
    {
        $client = $this->account(RoleName::Client); $provider = $this->account(RoleName::Provider);
        $inquiry = $this->inquiry($client, $provider);
        $outgoing = $this->message($client, $inquiry, 'Mensaje del cliente.');
        $this->message($provider, $inquiry, 'Primera respuesta.');
        $last = $this->message($provider, $inquiry, 'Segunda respuesta.');
        $tracking = app(InquiryReadTracking::class);
        $this->assertSame(2, $tracking->unreadFor($client, $inquiry));
        $this->assertSame(1, $tracking->unreadFor($provider, $inquiry));
        $before = $inquiry->messages()->orderBy('id')->get()->toArray();
        $notices = InquiryNotification::all()->toArray();
        $page = $this->actingAs($client, 'web')->get(route('inquiries.show', $inquiry))->assertOk()->assertSee('2 mensajes recibidos sin leer');
        $this->post(route('conversations.read', $inquiry), ['read_token' => $page->viewData('messageReadToken')])->assertStatus(303)->assertSessionHas('success');
        $progress = InquiryReadProgress::sole();
        $this->assertSame($last->id, $progress->last_read_message_id);
        $this->assertSame($client->id, $progress->user->id);
        $this->assertSame($inquiry->id, $progress->inquiry->id);
        $this->assertSame($last->id, $progress->lastReadMessage->id);
        $this->assertSame(0, $tracking->unreadFor($client, $inquiry));
        $this->assertSame(1, $tracking->unreadFor($provider, $inquiry));
        $providerToken = $this->readToken($provider, $inquiry);
        $this->actingAs($provider, 'web')->post(route('conversations.read', $inquiry), ['read_token' => $providerToken])->assertStatus(303);
        $this->assertSame($outgoing->id, $provider->inquiryReadProgress()->sole()->last_read_message_id);
        $this->assertDatabaseCount('service_inquiry_reads', 2);
        $this->assertSame($before, $inquiry->messages()->orderBy('id')->get()->toArray());
        $this->assertSame($notices, InquiryNotification::all()->toArray());
        $this->assertArrayNotHasKey('last_read_message', $progress->toArray());
    }

    public function test_gets_do_not_write_and_empty_or_outgoing_only_pages_offer_no_read_action(): void
    {
        $client = $this->account(RoleName::Client); $inquiry = $this->inquiry($client);
        $this->actingAs($client, 'web')->get(route('inquiries.show', $inquiry))->assertOk()->assertDontSee('name="read_token"', false);
        $this->message($client, $inquiry);
        $this->get(route('inquiries.show', $inquiry))->assertOk()->assertSee('0 mensajes recibidos sin leer')->assertDontSee('name="read_token"', false);
        $this->get(route('conversations.index'))->assertOk();
        $this->assertDatabaseCount('service_inquiry_reads', 0);
    }

    public function test_repeated_and_obsolete_tokens_keep_the_most_advanced_marker_and_timestamp(): void
    {
        $client = $this->account(RoleName::Client); $provider = $this->account(RoleName::Provider);
        $inquiry = $this->inquiry($client, $provider); $this->freezeTime();
        $first = $this->message($provider, $inquiry); $old = $this->readToken($client, $inquiry);
        $this->actingAs($client, 'web')->post(route('conversations.read', $inquiry), ['read_token' => $old])->assertStatus(303);
        $initial = InquiryReadProgress::sole()->getAttributes();
        $this->travel(1)->hour();
        $this->post(route('conversations.read', $inquiry), ['read_token' => $old])->assertStatus(303);
        $this->assertSame($initial, InquiryReadProgress::sole()->getAttributes());
        $newMessage = $this->message($provider, $inquiry); $newToken = $this->readToken($client, $inquiry);
        $this->post(route('conversations.read', $inquiry), ['read_token' => $newToken])->assertStatus(303);
        $advanced = InquiryReadProgress::sole()->getAttributes();
        $this->assertSame($newMessage->id, $advanced['last_read_message_id']);
        $this->assertSame($initial['created_at'], $advanced['created_at']);
        $this->assertNotSame($initial['updated_at'], $advanced['updated_at']);
        $this->travel(1)->hour();
        $this->post(route('conversations.read', $inquiry), ['read_token' => $old])->assertStatus(303);
        $this->assertSame($advanced, InquiryReadProgress::sole()->getAttributes());
        $this->assertGreaterThan($first->id, $newMessage->id);
    }

    public function test_a_page_snapshot_does_not_mark_new_arrivals_even_if_their_dates_are_older(): void
    {
        $client = $this->account(RoleName::Client); $provider = $this->account(RoleName::Provider);
        $inquiry = $this->inquiry($client, $provider); $first = $this->message($provider, $inquiry);
        $page = $this->actingAs($client, 'web')->get(route('inquiries.show', $inquiry))->assertOk();
        $token = $page->viewData('messageReadToken');
        $this->message($client, $inquiry, 'El propio no cuenta.');
        $later = $this->message($provider, $inquiry, 'Recibido después del GET.');
        DB::table('service_inquiry_messages')->where('id', $later->id)->update(['created_at' => now()->subDay()]);
        $this->post(route('conversations.read', $inquiry), ['read_token' => $token])->assertStatus(303);
        $this->assertSame($first->id, InquiryReadProgress::sole()->last_read_message_id);
        $this->assertSame(1, app(InquiryReadTracking::class)->unreadFor($client, $inquiry));
        $this->message($provider, $inquiry, 'Otra llegada posterior.');
        $this->post(route('conversations.read', $inquiry), ['read_token' => $token])->assertStatus(303);
        $this->assertSame(2, app(InquiryReadTracking::class)->unreadFor($client, $inquiry));
    }

    public function test_terminal_and_paused_inquiries_allow_reading_without_changing_state_or_messages(): void
    {
        foreach ([InquiryStatus::Rejected, InquiryStatus::Cancelled, InquiryStatus::Completed] as $status) {
            $client = $this->account(RoleName::Client); $provider = $this->account(RoleName::Provider);
            $inquiry = $this->inquiry($client, $provider, $status);
            // Fixtures anteriores a 5B: mensajes que se conservaron al cerrar la solicitud.
            DB::table('service_inquiry_messages')->insert(['service_inquiry_id' => $inquiry->id, 'author_user_id' => $provider->id,
                'body' => 'Historial conservado.', 'idempotency_key' => (string) \Illuminate\Support\Str::uuid(), 'created_at' => now()]);
            $inquiry->service->update(['status' => 'paused']); $before = $inquiry->getAttributes();
            $page = $this->actingAs($client, 'web')->get(route('inquiries.show', $inquiry))->assertOk()->assertSee('modo solo lectura')->assertSee('Marcar mensajes como leídos');
            $this->post(route('conversations.read', $inquiry), ['read_token' => $page->viewData('messageReadToken')])->assertStatus(303);
            $this->assertSame(0, app(InquiryReadTracking::class)->unreadFor($client, $inquiry));
            $this->assertSame($before, $inquiry->fresh()->getAttributes());
            $this->postJson(route('inquiries.messages.store', $inquiry), $this->messageData())->assertStatus(409);
        }
    }

    public function test_fields_ciphertext_and_tokens_cannot_select_other_users_inquiries_or_outgoing_markers(): void
    {
        $client = $this->account(RoleName::Client); $provider = $this->account(RoleName::Provider);
        $inquiry = $this->inquiry($client, $provider); $outgoing = $this->message($client, $inquiry); $this->message($provider, $inquiry);
        $token = $this->readToken($client, $inquiry); $other = $this->inquiry($client, $provider);
        $this->actingAs($client, 'web');
        $fields = ['user_id' => $provider->id, 'recipient_id' => $provider->id, 'service_inquiry_id' => $other->id,
            'inquiry_id' => $other->id, 'last_read_message_id' => 999999, 'message_id' => 999999, 'through' => 999999, 'read_at' => '2030-01-01'];
        $this->postJson(route('conversations.read', $inquiry), ['read_token' => $token] + $fields)->assertUnprocessable()->assertJsonValidationErrors(array_keys($fields));
        foreach (['bad', substr($token, 0, -5).'XXXXX', Crypt::encryptString(json_encode(['user_id' => $client->id, 'inquiry_id' => $inquiry->id, 'through' => $outgoing->id]))] as $bad) {
            $this->postJson(route('conversations.read', $inquiry), ['read_token' => $bad])->assertUnprocessable();
        }
        $this->postJson(route('conversations.read', $other), ['read_token' => $token])->assertUnprocessable();
        $this->actingAs($provider, 'web')->postJson(route('conversations.read', $inquiry), ['read_token' => $token])->assertUnprocessable();
        $this->assertDatabaseCount('service_inquiry_reads', 0);
    }

    public function test_third_parties_and_admins_cannot_mark_or_query_individual_counters(): void
    {
        $client = $this->account(RoleName::Client); $provider = $this->account(RoleName::Provider);
        $inquiry = $this->inquiry($client, $provider); $this->message($provider, $inquiry, 'PRIVATE-COUNTER-BODY');
        $token = $this->readToken($client, $inquiry);
        foreach ([[RoleName::Client], [RoleName::Provider], [RoleName::Admin], [RoleName::Admin, RoleName::Client, RoleName::Provider]] as $roles) {
            $third = $this->account(...$roles);
            $this->actingAs($third, 'web')->postJson(route('conversations.read', $inquiry), ['read_token' => $token])->assertForbidden()->assertDontSee('PRIVATE-COUNTER-BODY');
            $this->get(route('inquiries.show', $inquiry))->assertForbidden()->assertDontSee('PRIVATE-COUNTER-BODY');
            $this->assertSame(0, app(InquiryReadTracking::class)->totalUnread($third));
        }
        $this->assertDatabaseCount('service_inquiry_reads', 0);
        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        app(InquiryReadTracking::class)->unreadFor($third, $inquiry);
    }

    public function test_role_revocation_invalidates_prepared_actions_without_deleting_progress(): void
    {
        $client = $this->account(RoleName::Client, RoleName::Admin); $provider = $this->account(RoleName::Provider);
        $inquiry = $this->inquiry($client, $provider); $this->message($provider, $inquiry);
        $token = $this->readToken($client, $inquiry);
        $this->actingAs($client, 'web')->post(route('conversations.read', $inquiry), ['read_token' => $token])->assertStatus(303);
        $before = InquiryReadProgress::sole()->getAttributes();
        $client->roles()->detach($client->roles()->where('slug', 'client')->value('id'));
        $this->postJson(route('conversations.read', $inquiry), ['read_token' => $token])->assertForbidden();
        $this->get(route('conversations.index'))->assertForbidden();
        $this->assertSame(0, app(InquiryReadTracking::class)->totalUnread($client));
        $this->assertSame($before, InquiryReadProgress::sole()->getAttributes());
    }

    public function test_failure_and_outer_rollback_do_not_leave_progress_or_touch_4b_notifications(): void
    {
        $client = $this->account(RoleName::Client); $provider = $this->account(RoleName::Provider);
        $inquiry = $this->inquiry($client, $provider); $this->message($provider, $inquiry, 'PRIVATE-READ-BODY');
        $token = $this->readToken($client, $inquiry); $notices = InquiryNotification::all()->toArray();
        DB::unprepared("CREATE TRIGGER fail_read BEFORE INSERT ON service_inquiry_reads BEGIN SELECT RAISE(ABORT, 'Simulated read storage failure'); END");
        Log::spy();
        $this->actingAs($client, 'web')->postJson(route('conversations.read', $inquiry), ['read_token' => $token])->assertStatus(503)->assertDontSee('PRIVATE-READ-BODY')->assertDontSee('INSERT INTO');
        Log::shouldNotHaveReceived('error');
        DB::unprepared('DROP TRIGGER fail_read');
        $this->assertDatabaseCount('service_inquiry_reads', 0);
        DB::beginTransaction(); app(InquiryReadTracking::class)->mark($client, $inquiry->id, $token);
        $this->assertDatabaseCount('service_inquiry_reads', 1); DB::rollBack();
        $this->assertDatabaseCount('service_inquiry_reads', 0);
        $this->assertSame($notices, InquiryNotification::all()->toArray());
    }

    public function test_legacy_matching_email_and_guests_have_no_central_read_access(): void
    {
        $client = $this->account(RoleName::Client); $inquiry = $this->inquiry($client);
        $this->message($inquiry->service->providerProfile->user, $inquiry); $token = $this->readToken($client, $inquiry);
        $this->get(route('conversations.index'))->assertRedirect('/login');
        $this->postJson(route('conversations.read', $inquiry), ['read_token' => $token])->assertUnauthorized();
        $legacy = Profesionista::create(['nombre' => 'Anterior', 'email' => $client->email, 'cedula' => 'LEGACY-5B', 'password' => Hash::make('old-password'), 'google_access_token' => 'fake-preserved']);
        $before = $legacy->fresh()->getAttributes(); session()->forget('url.intended');
        $this->post('/login-profesionista', ['email' => $legacy->email, 'password' => 'old-password'])->assertRedirect('/dashboard-profesionista');
        $this->get(route('conversations.index'))->assertRedirect('/login');
        $this->postJson(route('conversations.read', $inquiry), ['read_token' => $token])->assertUnauthorized();
        $this->get('/solicitudes-citas')->assertOk();
        $this->assertSame($before, $legacy->fresh()->getAttributes());
        $this->assertDatabaseCount('service_inquiry_reads', 0); $this->assertDatabaseCount('citas', 0);
    }
}
