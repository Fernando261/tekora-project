<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\ActivityNotification;
use App\Models\InquiryNotification;
use App\Services\NotificationInbox;
use App\Services\ReviewWorkflow;
use Illuminate\Support\Facades\DB;
use Tests\Support\ReviewFixtures;
use Tests\TestCase;

class ActivityInboxTest extends TestCase
{
    use ReviewFixtures;

    public function test_combined_inbox_is_sql_paginated_with_deterministic_date_source_and_id_order(): void
    {
        [$client, $provider, $inquiry, $reservation] = $this->reviewContext(); $review = $this->publishReview($reservation);
        for ($i = 0; $i < 13; $i++) $this->inquiry(null, $provider);
        $this->travel(1)->hours(); app(ReviewWorkflow::class)->withdraw($client, $review->id);
        $expected = [];
        foreach (InquiryNotification::where('user_id', $provider->id)->get() as $n) $expected[] = ['date' => $n->getRawOriginal('created_at'), 'source' => 'inquiry', 'id' => $n->id];
        foreach (ActivityNotification::where('user_id', $provider->id)->get() as $n) $expected[] = ['date' => $n->created_at, 'source' => 'activity', 'id' => $n->id];
        usort($expected, fn ($a, $b) => [$b['date'], $b['source'], $b['id']] <=> [$a['date'], $a['source'], $a['id']]);
        DB::enableQueryLog(); DB::flushQueryLog();
        $response = $this->actingAs($provider)->get(route('notifications.index'))->assertOk()->assertSee('Solicitudes')->assertSee('Reseñas')->assertSee('Página 1 de 2');
        $queries = DB::getQueryLog(); DB::disableQueryLog(); $page = $response->viewData('notifications');
        $this->assertSame(count($expected), $page->total()); $this->assertCount(12, $page);
        $keys = fn ($rows) => $rows->getCollection()->map(fn ($row) => $row->source_type.':'.$row->id)->all();
        $this->assertSame(array_map(fn ($r) => $r['source'].':'.$r['id'], array_slice($expected, 0, 12)), $keys($page));
        $second = $this->get(route('notifications.index', ['page' => 2]))->viewData('notifications');
        $this->assertSame(array_map(fn ($r) => $r['source'].':'.$r['id'], array_slice($expected, 12)), $keys($second));
        $sql = implode('\n', array_column($queries, 'query'));
        $this->assertStringContainsString('union all', $sql); $this->assertStringContainsString('limit 12 offset 0', $sql);
        $this->assertSame(count($expected), app(NotificationInbox::class)->unreadCount($provider));
        $this->assertSame(0, ActivityNotification::whereNotNull('read_at')->count()); $this->assertSame(0, InquiryNotification::whereNotNull('read_at')->count());
    }

    public function test_shared_counter_and_reading_each_source_preserve_all_business_resources(): void
    {
        [$client, $provider, $inquiry, $reservation] = $this->reviewContext(); $review = $this->publishReview($reservation);
        $tables = ['service_inquiries', 'service_reservations', 'reservation_events', 'service_reviews', 'service_inquiry_messages', 'service_inquiry_reads'];
        $before = $this->reservationRows($tables); $inbox = app(NotificationInbox::class);
        $this->assertSame(3, $inbox->unreadCount($provider)); // Solicitud + propuesta + publicación de reseña.
        $this->assertSame(3, $inbox->unreadCount($client)); // Aceptación + confirmación + finalización.
        $notice = ActivityNotification::where('user_id', $provider->id)->where('event', 'review_published')->sole();
        $this->actingAs($provider)->get(route('account.provider'))->assertOk()->assertSee('3 sin leer, avisos de solicitudes, reservas y reseñas');
        $this->post(route('notifications.activity.read', $notice))->assertStatus(303); $this->assertSame(2, $inbox->unreadCount($provider));
        $readAt = $notice->fresh()->read_at; $this->travel(1)->hours(); $this->post(route('notifications.activity.read', $notice))->assertStatus(303);
        $this->assertSame($readAt, $notice->fresh()->read_at);
        $this->post(route('notifications.read', $provider->inquiryNotifications()->sole()))->assertStatus(303); $this->assertSame(1, $inbox->unreadCount($provider));
        $this->assertSame($before, $this->reservationRows($tables)); $this->assertSame(3, $inbox->unreadCount($client));
    }

    public function test_review_links_only_point_to_visible_public_service_even_after_withdrawal(): void
    {
        [$client, $provider, $inquiry, $reservation] = $this->reviewContext(); $review = $this->publishReview($reservation, 1, 'PRIVATE-REVIEW-CONTENT');
        app(ReviewWorkflow::class)->withdraw($client, $review->id);
        $this->actingAs($provider)->get(route('notifications.index'))->assertOk()->assertSee('href="'.route('catalog.show', $inquiry->service_id).'#resenas"', false)
            ->assertDontSee('PRIVATE-REVIEW-CONTENT')->assertDontSee($client->name)->assertDontSee($client->email)->assertDontSee(route('reviews.show', $review));
        $inquiry->service->update(['status' => 'paused']);
        $this->get(route('notifications.index'))->assertOk()->assertSee('El servicio no está disponible públicamente.')->assertDontSee('href="'.route('catalog.show', $inquiry->service_id).'#resenas"', false);
        // El aviso publicado sigue pudiendo leerse después del retiro: su origen es histórico.
        $published = ActivityNotification::where('service_review_id', $review->id)->where('event', 'review_published')->sole();
        $this->post(route('notifications.activity.read', $published))->assertStatus(303); $this->assertNotNull($published->fresh()->read_at);
    }

    public function test_revoked_permissions_hide_private_links_without_losing_notices_or_read_access(): void
    {
        [$client, $provider, , $reservation] = $this->reviewContext();
        $client->roles()->detach();
        $this->actingAs($client)->get(route('notifications.index'))->assertOk()->assertSee('Se confirmó una reserva.')->assertSee('permisos actuales')
            ->assertDontSee('href="'.route('reservations.show', $reservation).'"', false);
        $this->get(route('reservations.show', $reservation))->assertForbidden();
        $notice = ActivityNotification::where('user_id', $client->id)->firstOrFail();
        $this->post(route('notifications.activity.read', $notice))->assertStatus(303); $this->assertNotNull($notice->fresh()->read_at);
    }

    public function test_link_batch_authorization_matches_resource_policies_for_both_experiences(): void
    {
        [$client, $provider, $inquiry, $reservation] = $this->reviewContext();
        foreach ([$client, $provider] as $user) {
            $entries = app(NotificationInbox::class)->listing($user);
            foreach ($entries as $entry) {
                $resource = $entry->source_type === 'inquiry' ? $inquiry : $reservation;
                $this->assertSame($user->can('view', $resource), $entry->detail_url !== null);
            }
        }
        $provider->roles()->detach($provider->roles()->where('slug', 'provider')->value('roles.id'));
        foreach (app(NotificationInbox::class)->listing($provider) as $entry) $this->assertNull($entry->detail_url);
    }

    public function test_inbox_link_queries_are_bounded_and_never_select_private_payloads(): void
    {
        [$client, $provider, $inquiry, $reservation] = $this->reviewContext(); $review = $this->publishReview($reservation); app(ReviewWorkflow::class)->withdraw($client, $review->id);
        $inbox = app(NotificationInbox::class);
        DB::enableQueryLog(); DB::flushQueryLog(); $small = $inbox->listing($provider); $smallQueries = DB::getQueryLog(); DB::disableQueryLog();
        for ($i = 0; $i < 5; $i++) { $r = $this->completeForReview($inquiry); $this->publishReview($r); }
        DB::enableQueryLog(); DB::flushQueryLog(); $large = $inbox->listing($provider); $largeQueries = DB::getQueryLog(); DB::disableQueryLog();
        $this->assertLessThanOrEqual(7, count($smallQueries)); $this->assertLessThanOrEqual(7, count($largeQueries)); $this->assertCount(12, $large);
        foreach ($largeQueries as $query) foreach (['"comment"', '"rating"', '"message"', '"body"', '"email"', '"name"'] as $private) $this->assertStringNotContainsString($private, $query['query']);
        foreach ($large as $entry) $this->assertStringNotContainsString('service_review_id', json_encode($entry));
    }
}
