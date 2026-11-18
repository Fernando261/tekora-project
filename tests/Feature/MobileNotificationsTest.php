<?php

namespace Tests\Feature;

use App\Enums\InquiryStatus;
use App\Enums\RoleName;
use App\Services\NotificationInbox;
use App\Services\ReservationWorkflow;
use App\Services\ReviewWorkflow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\MobileOperationsFixtures;
use Tests\TestCase;

class MobileNotificationsTest extends TestCase
{
    use MobileOperationsFixtures;

    private function notices(): array
    {
        [$client, $provider, $inquiry] = $this->reservationContext();
        $reservation = $this->proposeReservation($inquiry);
        app(ReservationWorkflow::class)->decide($provider, $reservation->id, 'confirm', ['version' => 1]);

        return [$client, $provider, $inquiry, $reservation];
    }

    private function business(): array
    {
        return $this->reservationRows(['service_inquiries', 'service_reservations', 'reservation_events', 'service_inquiry_messages',
            'service_reviews', 'reservation_holds', 'reservation_hold_events', 'reservation_occupancy', 'service_quotes', 'quote_acceptances']);
    }

    private function integrity(): void
    {
        $this->assertSame('ok', DB::selectOne('PRAGMA integrity_check')->integrity_check);
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
        $this->assertSame(1, (int) DB::selectOne('PRAGMA foreign_keys')->foreign_keys);
    }

    public function test_feed_and_count_are_private_minimal_read_only_and_source_ids_do_not_collide(): void
    {
        [$client, $provider, $inquiry, $reservation] = $this->notices();
        $before = $this->business();
        $notifications = $this->reservationRows(['inquiry_notifications', 'activity_notifications']);
        $headers = $this->bearer($client);
        $data = $this->get('/api/v1/notifications', $headers)->assertOk()->assertHeader('Content-Type', 'application/json')
            ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertJsonPath('meta.total', 2)->assertJsonCount(2, 'data')->assertJsonMissingPath('links')->json('data');
        $this->assertSame(['inquiry:2', 'activity:2'], array_column($data, 'id'));
        foreach ($data as $row) {
            $this->assertSame(['id', 'source_type', 'source_id', 'event', 'label', 'created_at', 'read_at', 'resource'], array_keys($row));
            $this->assertSame($row['source_type'].':'.$row['source_id'], $row['id']);
            $this->assertSame(['type', 'id'], array_keys($row['resource']));
            $this->assertStringEndsWith('Z', $row['created_at']);
            $this->assertNull($row['read_at']);
            foreach ([$client->email, $provider->email, $inquiry->message, $inquiry->service_title, '/cuenta/', 'user_id', 'password', 'detail_url'] as $private) {
                $this->assertStringNotContainsString($private, json_encode($row));
            }
        }
        $this->getJson('/api/v1/notifications/unread-count', $headers)->assertExactJson(['data' => ['unread_count' => 2]]);
        $this->assertSame($before, $this->business());
        $this->assertSame($notifications, $this->reservationRows(['inquiry_notifications', 'activity_notifications']));
        $this->integrity();
    }

    public function test_dual_account_never_receives_or_reads_its_provider_inquiry_reservation_or_review_notices(): void
    {
        $this->travelTo(CarbonImmutable::parse('2030-01-01 12:00:00', 'UTC'));
        $dual = $this->account(RoleName::Client, RoleName::Provider);
        $provider = $this->account(RoleName::Provider);
        $other = $this->account(RoleName::Client);
        $own = $this->inquiry($dual, $provider, InquiryStatus::Accepted);
        $received = $this->inquiry($other, $dual, InquiryStatus::Accepted);
        $this->publishSchedule($provider);
        $this->publishSchedule($dual);
        $reservation = $this->proposeReservation($received);
        app(ReservationWorkflow::class)->decide($dual, $reservation->id, 'confirm', ['version' => 1]);
        $this->travelTo(CarbonImmutable::parse($reservation->ends_at_utc, 'UTC'));
        app(ReservationWorkflow::class)->decide($dual, $reservation->id, 'complete', ['version' => 2]);
        $review = app(ReviewWorkflow::class)->publish($other, $reservation->id, ['rating' => 5, 'comment' => 'Reseña privada para probar aislamiento.'])['review'];
        app(ReviewWorkflow::class)->withdraw($other, $review->id);
        $ownNotice = DB::table('inquiry_notifications')->where('user_id', $dual->id)->where('event', 'accepted')->sole();
        $headers = $this->bearer($dual);
        $this->getJson('/api/v1/notifications?per_page=1', $headers)->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', 'inquiry:'.$ownNotice->id)->assertJsonPath('data.0.resource.id', $own->id);
        $this->getJson('/api/v1/notifications/unread-count', $headers)->assertJsonPath('data.unread_count', 1);
        foreach (['inquiry' => 'inquiry_notifications', 'activity' => 'activity_notifications'] as $source => $table) {
            foreach (DB::table($table)->where('user_id', $dual->id)->get() as $notice) {
                if ($source === 'inquiry' && $notice->id === $ownNotice->id) {
                    continue;
                }
                $this->postJson('/api/v1/notifications/'.$source.'/'.$notice->id.'/read', [], $headers)->assertNotFound()->assertJsonPath('error.code', 'not_found');
                $this->assertNull(DB::table($table)->where('id', $notice->id)->value('read_at'));
            }
        }
        // The existing web inbox intentionally still contains both experiences.
        $this->assertGreaterThan(1, app(NotificationInbox::class)->listing($dual)->total());
        $this->integrity();
    }

    public static function sources(): array
    {
        return [['inquiry'], ['activity']];
    }

    #[DataProvider('sources')]
    public function test_read_preserves_first_utc_timestamp_and_only_modifies_its_own_source(string $source): void
    {
        [$client] = $this->notices();
        $before = $this->business();
        $table = $source === 'inquiry' ? 'inquiry_notifications' : 'activity_notifications';
        $notice = DB::table($table)->where('user_id', $client->id)->sole();
        $headers = $this->bearer($client);
        config(['app.timezone' => 'Asia/Tokyo']);
        $this->travelTo(CarbonImmutable::parse('2030-01-01 13:00:00', 'UTC'));
        $path = '/api/v1/notifications/'.$source.'/'.$notice->id.'/read';
        $first = $this->postJson($path, [], $headers)->assertOk()->assertJsonPath('data.id', $source.':'.$notice->id)
            ->assertJsonPath('data.read_at', '2030-01-01T13:00:00.000000Z')->json('data');
        $this->travel(1)->hour();
        $this->postJson($path, [], $headers)->assertOk()->assertExactJson(['data' => $first]);
        $this->assertSame('2030-01-01 13:00:00', DB::table($table)->where('id', $notice->id)->value('read_at'));
        $this->getJson('/api/v1/notifications/unread-count', $headers)->assertJsonPath('data.unread_count', 1);
        $this->assertSame(1, DB::table('inquiry_notifications')->whereNotNull('read_at')->count() + DB::table('activity_notifications')->whereNotNull('read_at')->count());
        $this->assertSame($before, $this->business());
        $this->integrity();
    }

    public function test_auth_requires_real_current_mobile_client_token_not_session_abilities_or_cached_roles(): void
    {
        [$client] = $this->notices();
        $paths = ['/api/v1/notifications', '/api/v1/notifications/unread-count'];
        foreach ($paths as $path) {
            $this->get($path)->assertUnauthorized()->assertJsonPath('error.code', 'unauthenticated');
        }
        $this->postJson('/api/v1/notifications/inquiry/2/read')->assertUnauthorized();
        $this->actingAs($client, 'web')->get('/api/v1/notifications')->assertUnauthorized();
        foreach ([['other', ['client:read']], ['mobile:test', ['provider:manage']]] as [$name, $abilities]) {
            Auth::forgetGuards();
            $token = $client->createToken($name, $abilities, now()->addDay())->plainTextToken;
            $this->getJson($paths[0], ['Authorization' => 'Bearer '.$token])->assertForbidden();
        }
        $token = $client->createToken('mobile:expired', ['client:read'], now()->subSecond())->plainTextToken;
        Auth::forgetGuards();
        $this->getJson($paths[0], ['Authorization' => 'Bearer '.$token])->assertUnauthorized();
        $headers = $this->bearer($client);
        $client->load('roles');
        $client->roles()->detach();
        foreach ($paths as $path) {
            $this->getJson($path, $headers)->assertForbidden();
        }
        $this->postJson('/api/v1/notifications/inquiry/2/read', [], $headers)->assertForbidden();
        $this->assertSame(0, app(NotificationInbox::class)->clientUnreadCount($client));
        foreach ([RoleName::Provider, RoleName::Admin] as $role) {
            $this->getJson($paths[0], $this->bearer($this->account($role)))->assertForbidden();
        }
    }

    public function test_foreign_missing_malformed_and_provider_only_ids_are_hidden_before_payload_validation(): void
    {
        [$client, $provider] = $this->notices();
        $other = $this->account(RoleName::Client);
        foreach ([$other, $provider] as $actor) {
            $headers = $this->bearer($actor);
            foreach (['inquiry/2', 'activity/2', 'inquiry/999999', 'activity/999999', 'other/2', 'inquiry/0', 'inquiry/9223372036854775808', 'inquiry/01', 'inquiry/-1'] as $id) {
                $this->postJson('/api/v1/notifications/'.$id.'/read', ['user_id' => $client->id], $headers)->assertNotFound();
            }
        }
        $this->getJson('/api/v1/notifications', $this->bearer($other))->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/notifications/unread-count', $this->bearer($other))->assertExactJson(['data' => ['unread_count' => 0]]);
    }

    public static function invalidQueries(): array
    {
        return [['page=0'], ['page=-1'], ['page=10001'], ['page[]=1'], ['page=no'], ['per_page=0'], ['per_page=51'], ['per_page[]=1'], ['user_id=1'], ['source_type=activity'], ['unread=true']];
    }

    #[DataProvider('invalidQueries')]
    public function test_list_rejects_unexpected_or_unbounded_parameters(string $query): void
    {
        $client = $this->account(RoleName::Client);
        $this->getJson('/api/v1/notifications?'.$query, $this->bearer($client))->assertUnprocessable()->assertJsonPath('error.code', 'validation_failed');
    }

    public function test_count_and_read_allow_no_client_fields_or_method_overrides(): void
    {
        [$client] = $this->notices();
        $headers = $this->bearer($client);
        $this->getJson('/api/v1/notifications/unread-count?page=1', $headers)->assertUnprocessable();
        foreach ([['read_at' => '1990-01-01'], ['user_id' => $client->id], ['event' => 'completed'], ['id' => 2], ['source_type' => 'activity'], ['_token' => 'invented']] as $body) {
            $this->postJson('/api/v1/notifications/inquiry/2/read', $body, $headers)->assertUnprocessable();
        }
        $this->getJson('/api/v1/notifications/inquiry/2/read', $headers)->assertStatus(405);
        $this->patchJson('/api/v1/notifications/inquiry/2/read', [], $headers)->assertStatus(405);
        $this->assertNull(DB::table('inquiry_notifications')->where('id', 2)->value('read_at'));
    }

    public function test_sql_filters_identity_resources_role_and_events_before_counts_order_and_pagination(): void
    {
        $dual = $this->account(RoleName::Client, RoleName::Provider);
        $provider = $this->account(RoleName::Provider);
        $own = $this->inquiry($dual, $provider, InquiryStatus::Accepted);
        for ($i = 0; $i < 13; $i++) {
            $this->inquiry(null, $dual);
        }
        // Inquiry table permits legacy recipients: filter must reject even an owned notice about another client's resource.
        $foreign = $this->inquiry(null, $provider, InquiryStatus::Accepted);
        $forged = DB::table('inquiry_notifications')->insertGetId(['user_id' => $dual->id, 'service_inquiry_id' => $foreign->id, 'event' => 'accepted', 'created_at' => '2099-01-01 00:00:00']);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->getJson('/api/v1/notifications?per_page=1', $this->bearer($dual))->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.resource.id', $own->id);
        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();
        $feedQueries = array_values(array_filter($queries, fn ($sql) => str_contains($sql, 'notification_feed')));
        $this->assertCount(2, $feedQueries);
        foreach ($feedQueries as $sql) {
            foreach (['union all', 'client_user_id', 'role_user', 'roles', 'service_inquiries', 'service_reservations'] as $required) {
                $this->assertStringContainsString($required, $sql);
            }
        }
        $this->assertStringContainsString('limit 1 offset 0', $feedQueries[1]);
        $this->getJson('/api/v1/notifications?page=2&per_page=1', $this->bearer($dual))->assertNotFound();
        $this->getJson('/api/v1/notifications/unread-count', $this->bearer($dual))->assertJsonPath('data.unread_count', 1);
        $this->postJson('/api/v1/notifications/inquiry/'.$forged.'/read', [], $this->bearer($dual))->assertNotFound();
    }

    public function test_pagination_stable_ties_empty_feed_and_web_read_are_shared_without_side_effects(): void
    {
        $client = $this->account(RoleName::Client);
        $provider = $this->account(RoleName::Provider);
        for ($i = 0; $i < 13; $i++) {
            $this->inquiry($client, $provider, InquiryStatus::Accepted);
        }
        $expected = DB::table('inquiry_notifications')->where('user_id', $client->id)->orderByDesc('created_at')->orderByDesc('id')->pluck('id')->map(fn ($id) => 'inquiry:'.$id)->all();
        $headers = $this->bearer($client);
        $first = $this->getJson('/api/v1/notifications', $headers)->assertJsonPath('meta.total', 13)->assertJsonPath('meta.last_page', 2)->json('data');
        $second = $this->getJson('/api/v1/notifications?page=2', $headers)->json('data');
        $this->assertSame($expected, array_column([...$first, ...$second], 'id'));
        $id = $first[0]['source_id'];
        $this->actingAs($client, 'web')->post(route('notifications.read', $id))->assertStatus(303);
        $readAt = DB::table('inquiry_notifications')->where('id', $id)->value('read_at');
        $this->travel(1)->hour();
        $this->postJson('/api/v1/notifications/inquiry/'.$id.'/read', [], $this->bearer($client))->assertOk()->assertJsonPath('data.read_at', CarbonImmutable::parse($readAt, 'UTC')->toISOString());
        $this->getJson('/api/v1/notifications/unread-count', $this->bearer($client))->assertJsonPath('data.unread_count', 12);
    }

    #[DataProvider('sources')]
    public function test_storage_errors_never_return_zero_or_claim_read_success_and_retry_remains_possible(string $source): void
    {
        [$client] = $this->notices();
        $headers = $this->bearer($client);
        $table = $source === 'inquiry' ? 'inquiry_notifications' : 'activity_notifications';
        DB::unprepared("CREATE TRIGGER m1_fail_read BEFORE UPDATE OF read_at ON $table BEGIN SELECT RAISE(ABORT, 'M1_SECRET_STORAGE_FAILURE'); END");
        $this->postJson('/api/v1/notifications/'.$source.'/2/read', [], $headers)->assertConflict()->assertJsonPath('error.code', 'conflict')->assertDontSee('M1_SECRET_STORAGE_FAILURE');
        $this->assertNull(DB::table($table)->where('id', 2)->value('read_at'));
        DB::unprepared('DROP TRIGGER m1_fail_read');
        $this->postJson('/api/v1/notifications/'.$source.'/2/read', [], $headers)->assertOk();
        DB::unprepared('DROP TABLE activity_notifications');
        foreach (['/api/v1/notifications', '/api/v1/notifications/unread-count'] as $path) {
            $this->getJson($path, $headers)->assertStatus(503)->assertJsonPath('error.code', 'temporarily_unavailable')->assertJsonMissingPath('data')->assertDontSee('SQLSTATE');
        }
        $this->postJson('/api/v1/notifications/inquiry/2/read', [], $headers)->assertStatus(503)->assertJsonMissingPath('data');
    }

    public static function reservationEvents(): array
    {
        return [['reject', false, ['reservation_rejected']], ['cancel', false, ['reservation_cancelled']],
            ['cancel', true, []], ['complete', false, ['reservation_completed', 'reservation_confirmed']]];
    }

    #[DataProvider('reservationEvents')]
    public function test_client_reservation_events_follow_existing_recipient_rules(string $action, bool $byClient, array $events): void
    {
        [$client, $provider, $inquiry] = $this->reservationContext();
        $reservation = $this->proposeReservation($inquiry);
        $workflow = app(ReservationWorkflow::class);
        $version = 1;
        if ($action === 'complete') {
            $workflow->decide($provider, $reservation->id, 'confirm', ['version' => $version++]);
            $this->travelTo(CarbonImmutable::parse($reservation->ends_at_utc, 'UTC'));
        }
        $workflow->decide($byClient ? $client : $provider, $reservation->id, $action, ['version' => $version]);
        $response = $this->getJson('/api/v1/notifications', $this->bearer($client))->assertOk();
        $actual = array_values(array_filter(array_column($response->json('data'), 'event'), fn ($e) => str_starts_with($e, 'reservation_')));
        sort($actual);
        sort($events);
        $this->assertSame($events, $actual);
        $this->assertNotContains('reservation_requested', array_column($response->json('data'), 'event'));
        $this->integrity();
    }

    public function test_read_throttle_is_enforced_even_for_retries(): void
    {
        [$client] = $this->notices();
        $headers = $this->bearer($client);
        for ($i = 0; $i < 30; $i++) {
            $this->postJson('/api/v1/notifications/inquiry/2/read', [], $headers)->assertOk();
        }
        $this->postJson('/api/v1/notifications/inquiry/2/read', [], $headers)->assertStatus(429)->assertJsonPath('error.code', 'rate_limited')->assertHeader('Retry-After');
    }
}
