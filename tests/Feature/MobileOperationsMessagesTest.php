<?php

namespace Tests\Feature;

use App\Enums\InquiryAction;
use App\Enums\RoleName;
use App\Services\InquiryWorkflow;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\MobileOperationsFixtures;
use Tests\TestCase;

#[Group('mobile-operations')]
class MobileOperationsMessagesTest extends TestCase
{
    use MobileOperationsFixtures;

    public function test_message_idempotency_normalization_conflict_xss_and_terminal_replay(): void
    {
        $inquiry = $this->inquiry();
        $client = $inquiry->client;
        $url = '/api/v1/inquiries/'.$inquiry->id.'/messages';
        $data = $this->messageData(" <script>alert('XSS')</script>\r\ntexto ");
        $created = $this->postJson($url, $data, $this->bearer($client))->assertCreated()->assertJsonPath('data.is_mine', true);
        $id = $created->json('data.id');
        $this->postJson($url, $data, $this->bearer($client))->assertOk()->assertJsonPath('data.id', $id);
        $this->postJson($url, ['body' => 'Otro texto', 'idempotency_key' => $data['idempotency_key']], $this->bearer($client))->assertConflict();
        app(InquiryWorkflow::class)->decide($client, $inquiry->id, InquiryAction::Cancel);
        $this->postJson($url, $data, $this->bearer($client))->assertOk()->assertJsonPath('data.id', $id);
        $this->postJson($url, $this->messageData('Nuevo mensaje tardío'), $this->bearer($client))->assertConflict();
        $page = $this->getJson($url, $this->bearer($client))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $page->assertJsonPath('data.0.body', "<script>alert('XSS')</script>\ntexto");
        foreach (['author_user_id', 'idempotency_key', 'email', 'password'] as $secret) {
            $this->assertStringNotContainsString('"'.$secret.'"', $page->getContent());
        }
        $this->assertDatabaseCount('service_inquiry_messages', 1);
    }

    public function test_cursor_pages_and_only_displayed_received_messages_bound_the_read_token(): void
    {
        $inquiry = $this->inquiry();
        $client = $inquiry->client;
        $provider = $inquiry->service->providerProfile->user;
        $messages = collect();
        for ($i = 0; $i < 22; $i++) {
            $messages->push($this->message($provider, $inquiry, 'Mensaje recibido '.$i));
        }
        $url = '/api/v1/inquiries/'.$inquiry->id.'/messages';
        $first = $this->getJson($url, $this->bearer($client))->assertOk()->assertJsonCount(20, 'data');
        $this->assertDatabaseCount('service_inquiry_reads', 0);
        $cursor = $first->json('meta.next_cursor');
        $oldPage = $this->getJson($url.'?messages_cursor='.urlencode($cursor), $this->bearer($client))->assertOk()->assertJsonCount(2, 'data');
        $this->assertSame([$messages[1]->id, $messages[0]->id], array_column($oldPage->json('data'), 'id'));
        $readUrl = $url.'/read';
        $this->postJson($readUrl, ['read_token' => $oldPage->json('meta.read_token')], $this->bearer($client))->assertOk()->assertJsonPath('data.last_read_message_id', $messages[1]->id);
        $this->postJson($readUrl, ['read_token' => $first->json('meta.read_token')], $this->bearer($client))->assertOk()->assertJsonPath('data.last_read_message_id', $messages[21]->id);
        $this->postJson($readUrl, ['read_token' => $oldPage->json('meta.read_token')], $this->bearer($client))->assertOk()->assertJsonPath('data.last_read_message_id', $messages[21]->id);
        $this->getJson($url, $this->bearer($client))->assertJsonPath('meta.read_token', null)->assertJsonPath('meta.unread_messages_count', 0);
        foreach (['bad', 'e30', str_repeat('x', 1025)] as $cursor) {
            $this->getJson($url.'?messages_cursor='.$cursor, $this->bearer($client))->assertUnprocessable()->assertJsonPath('error.code', 'validation_failed');
        }
    }

    public function test_read_tokens_are_bound_to_actor_and_conversation_and_do_not_include_own_messages(): void
    {
        $inquiry = $this->inquiry();
        $client = $inquiry->client;
        $provider = $inquiry->service->providerProfile->user;
        $this->message($client, $inquiry);
        $url = '/api/v1/inquiries/'.$inquiry->id.'/messages';
        $this->getJson($url, $this->bearer($client))->assertJsonPath('meta.read_token', null);
        $received = $this->message($provider, $inquiry);
        $token = $this->getJson($url, $this->bearer($client))->json('meta.read_token');
        $another = $this->inquiry($client);
        $foreignToken = $this->readToken($provider, $inquiry);
        foreach (['bad-token', $foreignToken] as $bad) {
            $this->postJson($url.'/read', ['read_token' => $bad], $this->bearer($client))->assertUnprocessable();
        }
        $this->postJson('/api/v1/inquiries/'.$another->id.'/messages/read', ['read_token' => $token], $this->bearer($client))->assertUnprocessable();
        $this->postJson($url.'/read', ['read_token' => $token, 'through' => $received->id + 1], $this->bearer($client))->assertUnprocessable();
        $this->assertDatabaseCount('service_inquiry_reads', 0);
        $this->postJson($url.'/read', ['read_token' => $token], $this->bearer($this->account(RoleName::Client)))->assertNotFound();
    }

    public function test_storage_failures_are_safe_and_atomic_for_messages_and_reads(): void
    {
        $inquiry = $this->inquiry();
        $client = $inquiry->client;
        $this->message($inquiry->service->providerProfile->user, $inquiry);
        $url = '/api/v1/inquiries/'.$inquiry->id.'/messages';
        $token = $this->getJson($url, $this->bearer($client))->json('meta.read_token');
        DB::unprepared("CREATE TRIGGER fail_api_message BEFORE INSERT ON service_inquiry_messages BEGIN SELECT RAISE(ABORT, 'PRIVATE BODY SQL'); END");
        $response = $this->postJson($url, $this->messageData('PRIVATE TEXT'), $this->bearer($client))->assertStatus(503)->assertJsonPath('error.code', 'temporarily_unavailable');
        $this->assertStringNotContainsString('PRIVATE', $response->getContent());
        $this->assertDatabaseCount('service_inquiry_messages', 1);
        DB::unprepared("CREATE TRIGGER fail_api_read BEFORE INSERT ON service_inquiry_reads BEGIN SELECT RAISE(ABORT, 'PRIVATE SQL'); END");
        $this->postJson($url.'/read', ['read_token' => $token], $this->bearer($client))->assertStatus(503)->assertJsonPath('error.code', 'temporarily_unavailable');
        $this->assertDatabaseCount('service_inquiry_reads', 0);
    }

    public function test_message_validation_and_write_limits_do_not_depend_on_web_guard(): void
    {
        $inquiry = $this->inquiry();
        $client = $inquiry->client;
        $url = '/api/v1/inquiries/'.$inquiry->id.'/messages';
        foreach ([['body' => 'x'], $this->messageData(str_repeat('x', 2001)), $this->messageData('   '),
            $this->messageData() + ['author_user_id' => 1]] as $data) {
            $this->postJson($url, $data, $this->bearer($client))->assertUnprocessable();
        }
        $this->travel(61)->seconds();
        for ($i = 0; $i < 10; $i++) {
            $this->postJson($url, $this->messageData('Mensaje '.$i), $this->bearer($client))->assertCreated();
        }
        $this->postJson($url, $this->messageData(), $this->bearer($client))->assertStatus(429)->assertHeader('Retry-After');
    }
}
