<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Facades\DB;
use Tests\Support\MessageFixtures;
use Tests\TestCase;

class InquiryMessagesPaginationTest extends TestCase
{
    use MessageFixtures;

    public function test_cursor_pagination_keeps_each_window_chronological_scoped_and_bounded_without_counting_the_history(): void
    {
        $client = $this->account(RoleName::Client);
        $inquiry = $this->inquiry($client);
        $this->freezeTime();
        for ($i = 1; $i <= 45; $i++) $this->message($client, $inquiry, 'TEXTO_'.str_pad((string) $i, 3, '0', STR_PAD_LEFT).'_FIN');
        $foreign = $this->inquiry();
        $this->message($foreign->client, $foreign, 'PRIVATE-FOREIGN-CONVERSATION');
        $queries = [];
        DB::listen(function ($event) use (&$queries) { if (str_contains($event->sql, 'service_inquiry_messages')) $queries[] = $event->sql; });
        $response = $this->actingAs($client, 'web')->get(route('inquiries.show', $inquiry))->assertOk()->assertSeeInOrder(['TEXTO_026_FIN', 'TEXTO_045_FIN'])
            ->assertDontSee('TEXTO_025_FIN')->assertDontSee('PRIVATE-FOREIGN-CONVERSATION');
        $first = $response->viewData('messages');
        $this->assertCount(20, $first);
        $second = $this->get($first->nextPageUrl())->assertOk()->assertSeeInOrder(['TEXTO_006_FIN', 'TEXTO_025_FIN'])->assertDontSee('TEXTO_026_FIN')->viewData('messages');
        $this->assertCount(20, $second);
        $third = $this->get($second->nextPageUrl())->assertOk()->assertSeeInOrder(['TEXTO_001_FIN', 'TEXTO_005_FIN'])->viewData('messages');
        $this->assertCount(5, $third);
        $this->assertFalse($third->hasMorePages());
        $this->assertSame($second->pluck('id')->all(), $this->get($third->previousPageUrl())->viewData('messages')->pluck('id')->all());
        $this->assertSame($first->pluck('id')->all(), $this->get($second->previousPageUrl())->viewData('messages')->pluck('id')->all());
        $ids = array_merge($first->pluck('id')->all(), $second->pluck('id')->all(), $third->pluck('id')->all());
        $this->assertCount(45, array_unique($ids));
        foreach ($queries as $sql) {
            $this->assertStringNotContainsString('count(', strtolower($sql));
            $this->assertStringContainsString('limit 21', strtolower($sql));
            $this->assertStringContainsString('service_inquiry_id', $sql);
        }
        $plan = DB::select('EXPLAIN QUERY PLAN SELECT * FROM service_inquiry_messages WHERE service_inquiry_id = ? ORDER BY created_at DESC, id DESC LIMIT 21', [$inquiry->id]);
        $this->assertStringContainsString('inquiry_messages_conversation_order', $plan[0]->detail);
        // Una clave de cursor no es un permiso: incluso la procedente de otra conversación conserva el scope.
        $cursor = new Cursor(['created_at' => $foreign->created_at->format('Y-m-d H:i:s'), 'id' => 99999], true);
        $this->get(route('inquiries.show', [$inquiry, 'messages_cursor' => $cursor->encode()]))->assertOk()->assertDontSee('PRIVATE-FOREIGN-CONVERSATION');
    }

    public function test_cursor_orders_by_timestamp_before_id_and_rejects_malformed_input_without_exposing_messages(): void
    {
        $client = $this->account(RoleName::Client);
        $inquiry = $this->inquiry($client);
        $older = $this->message($client, $inquiry, 'FECHA-MAS-ANTIGUA');
        $newer = $this->message($client, $inquiry, 'FECHA-MAS-RECIENTE');
        DB::table('service_inquiry_messages')->where('id', $older->id)->update(['created_at' => now()->addDay()]);
        $this->actingAs($client, 'web')->get(route('inquiries.show', $inquiry))->assertOk()->assertSeeInOrder(['FECHA-MAS-RECIENTE', 'FECHA-MAS-ANTIGUA']);
        foreach (['bad', ['array'], str_repeat('a', 1025), (new Cursor(['id' => $newer->id], true))->encode(),
            (new Cursor(['id' => ['bad'], 'created_at' => '2030-01-01 12:00:00'], true))->encode(),
            (new Cursor(['id' => 1, 'created_at' => '2030-99-01 12:00:00'], true))->encode()] as $cursor) {
            $this->getJson(route('inquiries.show', [$inquiry, 'messages_cursor' => $cursor]))->assertUnprocessable()->assertDontSee('FECHA-MAS-RECIENTE');
        }
    }
}
