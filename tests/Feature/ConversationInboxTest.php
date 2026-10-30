<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\InquiryReadProgress;
use App\Services\InquiryReadTracking;
use Illuminate\Support\Facades\DB;
use Tests\Support\ReadFixtures;
use Tests\TestCase;

class ConversationInboxTest extends TestCase
{
    use ReadFixtures;

    public function test_dual_account_gets_one_inbox_with_scoped_counts_for_both_experiences(): void
    {
        $dual = $this->account(RoleName::Client, RoleName::Provider);
        $provider = $this->account(RoleName::Provider); $client = $this->account(RoleName::Client);
        $sent = $this->inquiry($dual, $provider); $received = $this->inquiry($client, $dual);
        $this->message($provider, $sent); $this->message($provider, $sent);
        $this->message($client, $received);
        $this->message($dual, $sent); $this->message($dual, $received);
        $response = $this->actingAs($dual, 'web')->get(route('conversations.index'))->assertOk()->assertSee('3 mensajes sin leer');
        $rows = $response->viewData('conversations')->keyBy('id');
        $this->assertCount(2, $rows);
        $this->assertSame(2, $rows[$sent->id]->unread_messages_count);
        $this->assertSame(1, $rows[$received->id]->unread_messages_count);
        $this->assertSame(3, app(InquiryReadTracking::class)->totalUnread($dual));
        $this->post(route('conversations.read', $sent), ['read_token' => $this->readToken($dual, $sent)])->assertStatus(303);
        $this->get(route('account.index'))->assertOk()->assertSee('1 mensaje sin leer');
        $this->assertSame(1, app(InquiryReadTracking::class)->totalUnread($dual));
        $this->assertDatabaseCount('users', 3); $this->assertDatabaseCount('profesionistas', 0);
        $this->assertAuthenticatedAs($dual, 'web');
    }

    public function test_unread_conversations_are_prioritized_then_latest_activity_without_reading_on_get(): void
    {
        $client = $this->account(RoleName::Client); $provider = $this->account(RoleName::Provider);
        $unread = $this->inquiry($client, $provider); $this->message($provider, $unread);
        $read = $this->inquiry($client, $provider); $this->message($provider, $read);
        app(InquiryReadTracking::class)->mark($client, $read->id, $this->readToken($client, $read));
        $empty = $this->inquiry($client, $provider);
        $outgoing = $this->inquiry($client, $provider); $this->message($client, $outgoing);
        $before = InquiryReadProgress::all()->toArray();
        $rows = $this->actingAs($client, 'web')->get(route('conversations.index'))->assertOk()->viewData('conversations');
        $this->assertSame([$unread->id, $outgoing->id, $read->id, $empty->id], $rows->pluck('id')->all());
        $this->assertSame([1, 0, 0, 0], $rows->pluck('unread_messages_count')->all());
        $this->assertSame($before, InquiryReadProgress::all()->toArray());
    }

    public function test_paginated_inbox_only_loads_metadata_and_does_not_expose_bodies_or_foreign_requests(): void
    {
        $client = $this->account(RoleName::Client); $provider = $this->account(RoleName::Provider);
        for ($i = 0; $i < 14; $i++) { $inquiry = $this->inquiry($client, $provider); $this->message($provider, $inquiry, 'PRIVATE-INBOX-BODY'); }
        $foreign = $this->inquiry(); $this->message($foreign->client, $foreign, 'FOREIGN-INBOX-BODY');
        $queries = []; DB::listen(function ($event) use (&$queries) { $queries[] = $event->sql; });
        $response = $this->actingAs($client, 'web')->get(route('conversations.index'))->assertOk()->assertSee('14 mensajes sin leer')
            ->assertSee('Página 1 de 2')->assertDontSee('PRIVATE-INBOX-BODY')->assertDontSee('FOREIGN-INBOX-BODY');
        $rows = $response->viewData('conversations');
        $this->assertSame(14, $rows->total()); $this->assertCount(12, $rows);
        $this->assertNotContains($foreign->id, $rows->pluck('id')->all());
        $this->assertArrayNotHasKey('message', $rows->first()->getAttributes());
        $this->assertArrayNotHasKey('messages', $rows->first()->getRelations());
        foreach ($queries as $sql) $this->assertStringNotContainsString('"body"', $sql);
        $this->assertCount(2, $this->get(route('conversations.index', ['page' => 2]))->viewData('conversations'));
        foreach (['page=-1', 'page[]=1', 'page=10001'] as $query) $this->getJson('/cuenta/mensajes?'.$query)->assertUnprocessable();
        $this->assertDatabaseCount('service_inquiry_reads', 0);
    }

    public function test_current_roles_filter_both_inbox_and_global_count_even_for_a_dual_admin(): void
    {
        $dual = $this->account(RoleName::Client, RoleName::Provider, RoleName::Admin);
        $sent = $this->inquiry($dual); $received = $this->inquiry(null, $dual);
        $this->message($sent->service->providerProfile->user, $sent); $this->message($received->client, $received);
        $dual->roles()->detach($dual->roles()->where('slug', 'client')->value('id'));
        $rows = $this->actingAs($dual, 'web')->get(route('conversations.index'))->assertOk()->viewData('conversations');
        $this->assertSame([$received->id], $rows->pluck('id')->all());
        $this->assertSame(1, app(InquiryReadTracking::class)->totalUnread($dual));
        $this->get(route('inquiries.show', $sent))->assertForbidden();
        $dual->roles()->detach($dual->roles()->where('slug', 'provider')->value('id'));
        $this->get(route('conversations.index'))->assertForbidden();
        $this->assertSame(0, app(InquiryReadTracking::class)->totalUnread($dual));
        $this->get(route('account.index'))->assertOk()->assertDontSee('href="'.route('conversations.index').'"', false);
    }

    public function test_escaped_titles_paused_services_and_third_party_inboxes_do_not_reveal_private_content(): void
    {
        $client = $this->account(RoleName::Client); $provider = $this->account(RoleName::Provider);
        $inquiry = $this->inquiry($client, $provider); $this->message($provider, $inquiry, '<script>PRIVATE-READ-TEXT()</script>');
        DB::table('service_inquiries')->where('id', $inquiry->id)->update(['service_title' => '<img src=x onerror=BAD-TITLE()>']);
        $inquiry->service->update(['status' => 'paused', 'title' => 'PRIVATE-EDITED-TITLE']);
        $this->actingAs($client, 'web')->get(route('conversations.index'))->assertOk()->assertSee('&lt;img src=x onerror=BAD-TITLE()&gt;', false)
            ->assertDontSee('<img src=x onerror=BAD-TITLE()>', false)->assertDontSee('PRIVATE-READ-TEXT')->assertDontSee('PRIVATE-EDITED-TITLE');
        $this->assertSame(1, app(InquiryReadTracking::class)->totalUnread($client));
        foreach ([RoleName::Client, RoleName::Provider] as $role) {
            $this->actingAs($this->account($role), 'web')->get(route('conversations.index'))->assertOk()->assertSee('Aún no tienes conversaciones')->assertDontSee('BAD-TITLE');
        }
        $this->actingAs($this->account(RoleName::Admin), 'web')->get(route('conversations.index'))->assertForbidden();
    }

    public function test_old_pages_only_mark_their_bound_and_an_outgoing_only_window_cannot_mark_older_received_messages(): void
    {
        $client = $this->account(RoleName::Client); $provider = $this->account(RoleName::Provider);
        $inquiry = $this->inquiry($client, $provider);
        for ($i = 0; $i < 40; $i++) $this->message($provider, $inquiry);
        $first = $this->actingAs($client, 'web')->get(route('inquiries.show', $inquiry));
        $second = $this->get($first->viewData('messages')->nextPageUrl())->assertOk();
        $this->post(route('conversations.read', $inquiry), ['read_token' => $second->viewData('messageReadToken')])->assertStatus(303);
        $this->assertSame(20, app(InquiryReadTracking::class)->unreadFor($client, $inquiry));
        $this->message($provider, $inquiry, 'Posterior a la primera página.');
        $this->post(route('conversations.read', $inquiry), ['read_token' => $first->viewData('messageReadToken')])->assertStatus(303);
        $this->assertSame(1, app(InquiryReadTracking::class)->unreadFor($client, $inquiry));
        for ($i = 0; $i < 20; $i++) $this->message($client, $inquiry);
        $page = $this->get(route('inquiries.show', $inquiry))->assertOk()->assertSee('Revisa las otras páginas');
        $this->assertNull($page->viewData('messageReadToken'));
        $this->assertSame(1, app(InquiryReadTracking::class)->unreadFor($client, $inquiry));
    }
}
