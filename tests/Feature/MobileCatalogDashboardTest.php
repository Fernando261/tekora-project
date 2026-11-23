<?php

namespace Tests\Feature;

use App\Enums\InquiryStatus;
use App\Enums\RoleName;
use App\Payments\ServicePricing;
use App\Services\ClientDashboardSummary;
use App\Services\InquiryReadTracking;
use App\Services\NotificationInbox;
use App\Services\ReservationWorkflow;
use App\Support\BookingClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CommercialApiInventoryAssertions;
use Tests\Support\ReadFixtures;
use Tests\Support\SlotHoldFixtures;
use Tests\TestCase;

final class MobileCatalogDashboardTest extends TestCase
{
    use CommercialApiInventoryAssertions, ReadFixtures, SlotHoldFixtures;

    private const DASHBOARD = '/api/v1/me/dashboard';

    public function test_categories_match_the_web_order_and_only_project_active_public_fields(): void
    {
        $this->get('/api/v1/categories')->assertOk()->assertExactJson(['data' => []]);
        $this->category('last')->update(['name' => 'A', 'sort_order' => 20]);
        $this->category('first-z')->update(['name' => 'Z', 'sort_order' => 10]);
        $this->category('first-a')->update(['name' => 'A', 'sort_order' => 10]);
        $this->category('first-a-tie')->update(['name' => 'A', 'sort_order' => 10]);
        $this->category('hidden')->update(['is_active' => false, 'sort_order' => 0]);
        $data = $this->get('/api/v1/categories', ['Accept' => 'text/html'])->assertOk()
            ->assertHeader('Content-Type', 'application/json')->json('data');

        $this->assertSame(['first-a', 'first-a-tie', 'first-z', 'last'], array_column($data, 'slug'));
        $this->assertSame($this->get(route('catalog.index'))->assertOk()->viewData('categories')->toArray(),
            array_map(fn ($row) => ['name' => $row['name'], 'slug' => $row['slug']], $data));
        foreach ($data as $row) {
            $this->assertSame(['id', 'name', 'slug'], array_keys($row));
            $this->assertSame($this->category($row['slug'])->id, $row['id']);
        }
    }

    public function test_provider_services_have_catalog_prices_pagination_and_visibility(): void
    {
        $owner = $this->account(RoleName::Provider);
        $first = $this->service($owner);
        $path = '/api/v1/providers/'.$first->provider_profile_id.'/services';
        $this->get($path)->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.price', null);
        $newest = $this->service($owner, ['modality' => 'both']);
        app(ServicePricing::class)->publish($owner, $newest->id, [
            'amount_cents' => 100000, 'expected_version' => 0, 'idempotency_key' => (string) Str::uuid(),
        ]);
        $this->service($owner, ['status' => 'draft', 'title' => 'BORRADOR-PRIVADO']);
        $this->service($owner, ['status' => 'paused', 'title' => 'SERVICIO-PAUSADO']);
        $inactive = $this->category('inactive');
        $inactive->update(['is_active' => false]);
        $this->service($owner, ['service_category_id' => $inactive->id, 'title' => 'CATEGORIA-INACTIVA']);
        $this->service($this->account(RoleName::Provider), ['title' => 'SERVICIO-AJENO']);

        $service = $this->get('/api/v1/services/'.$newest->id)->assertOk()->json('data');
        $this->assertNotNull($service['price']);
        $this->assertSame('both', $service['modality']);
        $this->get($path.'?per_page=1')->assertOk()->assertExactJson(['data' => [$service],
            'meta' => ['current_page' => 1, 'per_page' => 1, 'last_page' => 2, 'total' => 2]]);
        $this->get($path.'?per_page=1&page=2')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $first->id);
        $this->get($path.'?per_page=1&page=3')->assertOk()->assertExactJson(['data' => [],
            'meta' => ['current_page' => 3, 'per_page' => 1, 'last_page' => 2, 'total' => 2]]);
        $this->get($path)->assertOk()->assertJsonPath('meta.per_page', 12)->assertJsonPath('meta.total', 2)
            ->assertDontSee($owner->email)->assertDontSee('user_id')->assertDontSee('price_version_id');

        $onlyHidden = $this->service($this->account(RoleName::Provider), ['status' => 'draft']);
        $empty = $this->account(RoleName::Provider)->providerProfile()->create(['display_name' => 'Sin servicios']);
        $missing = $this->get('/api/v1/providers/999999/services')->assertNotFound()->json();
        foreach ([$onlyHidden->provider_profile_id, $empty->id, 'abc', '0', '9223372036854775808'] as $id) {
            $this->get('/api/v1/providers/'.$id.'/services')->assertNotFound()->assertExactJson($missing);
        }
        $owner->roles()->detach();
        $this->get($path)->assertNotFound()->assertExactJson($missing);
    }

    public function test_catalog_rejects_unknown_parameters_invalid_pagination_and_get_bodies(): void
    {
        $service = $this->service($this->account(RoleName::Provider));
        $path = '/api/v1/providers/'.$service->provider_profile_id.'/services';
        foreach (['page=0' => 'page', 'page=10001' => 'page', 'per_page=51' => 'per_page', 'page[]=1' => 'page',
            'page=1.5' => 'page', 'per_page=' => 'per_page', 'status=draft' => 'request', 'provider_id=2' => 'request',
            'q=técnico' => 'request'] as $query => $field) {
            $this->get($path.'?'.$query)->assertUnprocessable()->assertJsonPath('error.code', 'validation_failed')
                ->assertJsonStructure(['error' => ['code', 'message', 'fields' => [$field]]]);
        }
        $this->get('/api/v1/categories?is_active=0')->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['request']]]);
        foreach ([$path, '/api/v1/categories'] as $endpoint) {
            $this->json('GET', $endpoint, ['per_page' => 1])->assertUnprocessable()
                ->assertJsonStructure(['error' => ['fields' => ['request']]]);
        }
    }

    public function test_dashboard_auth_scope_filters_empty_snapshot_and_no_cache(): void
    {
        $this->travelTo(CarbonImmutable::parse('2030-01-01 12:00:00', 'UTC'));
        $client = $this->account(RoleName::Client);
        $this->get(self::DASHBOARD)->assertUnauthorized()->assertJsonPath('error.code', 'unauthenticated');
        $this->actingAs($client, 'web')->get(self::DASHBOARD)->assertUnauthorized();
        auth('web')->logout();
        $this->get(self::DASHBOARD, $this->bearer($this->account(RoleName::Provider)))->assertForbidden();
        $this->get(self::DASHBOARD, $this->bearer($this->account(RoleName::Admin)))->assertForbidden();
        Auth::forgetGuards();
        $plain = $client->createToken('web-token', ['client:read'])->plainTextToken;
        $this->get(self::DASHBOARD, ['Authorization' => 'Bearer '.$plain])->assertForbidden();
        $headers = $this->bearer($client);
        $this->get(self::DASHBOARD, $headers)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff')->assertExactJson(['data' => [
                'as_of' => '2030-01-01T12:00:00.000000Z', 'timezone' => 'America/Mexico_City',
                'counts' => ['active' => 0, 'confirmed' => 0, 'requested' => 0, 'completed' => 0, 'cancelled' => 0,
                    'held' => 0, 'unread_conversations' => 0, 'unread_notifications' => 0],
                'upcoming_reservations' => [], 'recent_inquiries' => [],
            ]]);
        foreach (['user_id=2', 'client_user_id[]=2', 'role=provider', 'page=2'] as $query) {
            $this->get(self::DASHBOARD.'?'.$query, $headers)->assertUnprocessable()
                ->assertJsonPath('error.code', 'validation_failed')->assertJsonStructure(['error' => ['fields' => ['request']]]);
        }
        $this->json('GET', self::DASHBOARD, ['client_id' => 2], $headers)->assertUnprocessable();
        $client->roles()->detach();
        $this->get(self::DASHBOARD, $this->bearer($client))->assertForbidden()->assertJsonPath('error.code', 'forbidden');
    }

    public function test_dashboard_counters_and_lists_are_client_only_and_use_existing_read_markers(): void
    {
        $this->travelTo(CarbonImmutable::parse('2030-01-01 12:00:00', 'UTC'));
        $dual = $this->account(RoleName::Client, RoleName::Provider);
        $provider = $this->account(RoleName::Provider);
        $other = $this->account(RoleName::Client);
        $own = $this->inquiry($dual, $provider, InquiryStatus::Accepted);
        $second = $this->inquiry($dual, $provider, InquiryStatus::Accepted);
        $received = $this->inquiry($other, $dual, InquiryStatus::Accepted);
        $foreign = $this->inquiry($other, $provider, InquiryStatus::Accepted);
        $received->service->update(['title' => 'SOLO-PRESTADOR']);
        $foreign->service->update(['title' => 'SOLO-AJENO']);
        $seen = $this->message($provider, $own, 'MENSAJE-LEIDO');
        app(InquiryReadTracking::class)->mark($dual, $own->id, $this->readToken($dual, $own, collect([$seen])));
        $this->message($provider, $own, 'MENSAJE-PENDIENTE-UNO');
        $this->message($provider, $own, 'MENSAJE-PENDIENTE-DOS');
        $this->message($dual, $second, 'MENSAJE-PROPIO');
        $this->message($other, $received, 'MENSAJE-RECIBIDO-COMO-PRESTADOR');
        $this->message($provider, $foreign, 'MENSAJE-AJENO');
        $this->publishSchedule($provider);
        $this->publishSchedule($dual);
        $workflow = app(ReservationWorkflow::class);
        $next = $this->proposeReservation($own);
        $workflow->decide($provider, $next->id, 'confirm', ['version' => 1]);
        $this->proposeReservation($second, ['date' => '2030-01-08']);
        $this->proposeReservation($foreign, ['date' => '2030-01-09']);
        $providerBooking = $this->proposeReservation($received);
        $workflow->decide($dual, $providerBooking->id, 'confirm', ['version' => 1]);
        $notices = app(NotificationInbox::class);
        $unread = $notices->clientUnreadCount($dual);
        $this->assertGreaterThan(0, $unread);
        $this->assertGreaterThan($unread, $notices->unreadCount($dual));

        $headers = $this->bearer($dual);
        $response = $this->get(self::DASHBOARD, $headers)->assertOk()->assertJsonPath('data.counts', [
            'active' => 2, 'confirmed' => 1, 'requested' => 1, 'completed' => 0, 'cancelled' => 0,
            'held' => 0, 'unread_conversations' => 1, 'unread_notifications' => $unread,
        ])->assertJsonPath('data.upcoming_reservations', [[
            'id' => $next->id, 'inquiry_id' => $own->id, 'service_id' => $own->service_id, 'title' => $own->service->title,
            'status' => 'confirmed', 'starts_at' => '2030-01-07T15:00:00.000000Z', 'ends_at' => '2030-01-07T16:00:00.000000Z',
        ]])->assertJsonPath('data.recent_inquiries.1', [
            'id' => $own->id, 'service_id' => $own->service_id, 'title' => $own->service->title, 'status' => 'accepted',
            'created_at' => '2030-01-01T12:00:00.000000Z',
        ])->assertJsonPath('data.recent_inquiries.0.id', $second->id)->assertJsonCount(2, 'data.recent_inquiries');
        foreach (['SOLO-PRESTADOR', 'SOLO-AJENO', 'MENSAJE-', 'client_user_id', 'url', $provider->email, $other->email, $own->message] as $private) {
            $response->assertDontSee($private);
        }
        $this->get('/api/v1/notifications/unread-count', $headers)->assertOk()->assertExactJson(['data' => ['unread_count' => $unread]]);
        $this->get('/api/v1/reservations/'.$next->id, $headers)->assertOk();
        $this->get('/api/v1/reservations/'.$providerBooking->id, $headers)->assertNotFound();
        $this->get('/api/v1/inquiries/'.$foreign->id, $headers)->assertNotFound();
        $this->assertSame(2, app(ClientDashboardSummary::class)->build($dual)['unread']);
    }

    public function test_dashboard_counts_terminal_states_and_bounds_ordered_lists_to_five(): void
    {
        [$client, $provider, $first] = $this->reservationContext();
        $workflow = app(ReservationWorkflow::class);
        $done = $this->proposeReservation($first, ['date' => '2030-01-02']);
        $workflow->decide($provider, $done->id, 'confirm', ['version' => 1]);
        $cancelled = $this->proposeReservation($this->inquiry($client, $provider, InquiryStatus::Accepted), ['date' => '2030-01-05']);
        $workflow->decide($client, $cancelled->id, 'cancel', ['version' => 1]);
        $this->travelTo(CarbonImmutable::parse($done->ends_at_utc, 'UTC'));
        $workflow->decide($provider, $done->id, 'complete', ['version' => 2]);
        $confirmed = [];
        $recent = [];
        foreach ([9, 7, 8, 6, 10, 11] as $day) {
            $inquiry = $this->inquiry($client, $provider, InquiryStatus::Accepted);
            $reservation = $this->proposeReservation($inquiry, ['date' => sprintf('2030-01-%02d', $day)]);
            $workflow->decide($provider, $reservation->id, 'confirm', ['version' => 1]);
            $confirmed[$day] = $reservation->id;
            $recent[] = $inquiry->id;
        }
        $headers = $this->bearer($client);
        $response = $this->get(self::DASHBOARD, $headers)->assertOk()
            ->assertJsonPath('data.counts.completed', 1)->assertJsonPath('data.counts.cancelled', 1)
            ->assertJsonPath('data.counts.confirmed', 6)->assertJsonCount(5, 'data.upcoming_reservations')->assertJsonCount(5, 'data.recent_inquiries');
        ksort($confirmed);
        $this->assertSame(array_slice(array_values($confirmed), 0, 5), array_column($response->json('data.upcoming_reservations'), 'id'));
        $this->assertSame(array_slice(array_reverse($recent), 0, 5), array_column($response->json('data.recent_inquiries'), 'id'));
        $this->travelTo(CarbonImmutable::parse('2030-01-06 15:00:00', 'UTC'));
        $this->get(self::DASHBOARD, $headers)->assertOk()->assertJsonPath('data.counts.confirmed', 5)
            ->assertJsonPath('data.upcoming_reservations.0.id', $confirmed[7]);
    }

    public function test_dashboard_distinguishes_proposal_live_hold_and_exact_expiry_without_writes(): void
    {
        [$client, $provider, , , $reservation] = $this->pricedProposal();
        $headers = $this->bearer($client);
        $this->get(self::DASHBOARD, $headers)->assertOk()->assertJsonPath('data.counts.requested', 1)->assertJsonPath('data.counts.held', 0);
        app(ReservationWorkflow::class)->decide($provider, $reservation->id, 'confirm', $this->holdInput());
        $hold = DB::table('reservation_holds')->where('service_reservation_id', $reservation->id)->sole();
        $expiry = BookingClock::date($hold->expires_at_us);
        $tables = ['reservation_holds', 'reservation_hold_clock', 'reservation_hold_events', 'service_reservations', 'reservation_events',
            'inquiry_notifications', 'activity_notifications', 'service_inquiry_reads'];
        $before = $this->reservationRows($tables);
        $this->travelTo($expiry->subMicrosecond());
        $this->get(self::DASHBOARD, $headers)->assertOk()->assertJsonPath('data.as_of', $expiry->subMicrosecond()->toISOString())
            ->assertJsonPath('data.counts.requested', 0)->assertJsonPath('data.counts.held', 1)->assertJsonPath('data.upcoming_reservations', []);
        $this->travelTo($expiry);
        $this->get(self::DASHBOARD, $headers)->assertOk()->assertJsonPath('data.as_of', $expiry->toISOString())
            ->assertJsonPath('data.counts.requested', 0)->assertJsonPath('data.counts.held', 0)->assertJsonPath('data.upcoming_reservations', []);
        $this->assertSame($before, $this->reservationRows($tables));
        app(ReservationWorkflow::class)->refreshExpirations();
        $this->assertDatabaseHas('reservation_holds', ['service_reservation_id' => $reservation->id, 'state' => 'expired']);
        $this->get(self::DASHBOARD, $headers)->assertOk()->assertJsonPath('data.counts.held', 0)->assertJsonPath('data.counts.requested', 0);
    }

    public function test_dashboard_fails_closed_on_clock_rollback(): void
    {
        [$client, $provider, , , $reservation] = $this->pricedProposal();
        app(ReservationWorkflow::class)->decide($provider, $reservation->id, 'confirm', $this->holdInput());
        $headers = $this->bearer($client);
        $this->travelTo(BookingClock::date(BookingClock::observedMicros() - 1));
        $this->get(self::DASHBOARD, $headers)->assertStatus(503)->assertJsonPath('error.code', 'temporarily_unavailable')
            ->assertJsonMissingPath('data');
        $this->assertDatabaseHas('reservation_holds', ['service_reservation_id' => $reservation->id, 'state' => 'held']);
    }

    public function test_dashboard_does_not_replace_missing_or_invalid_inquiry_dates_with_the_current_time(): void
    {
        $inquiry = $this->inquiry();
        $headers = $this->bearer($inquiry->client);
        foreach ([null, 'tomorrow', '2030-02-30 12:00:00'] as $invalid) {
            DB::table('service_inquiries')->where('id', $inquiry->id)->update(['created_at' => $invalid]);
            $this->get(self::DASHBOARD, $headers)->assertStatus(503)
                ->assertJsonPath('error.code', 'temporarily_unavailable')->assertJsonMissingPath('data');
        }
    }

    public static function unavailableTables(): array
    {
        return [['activity_notifications'], ['service_inquiry_reads'], ['reservation_hold_clock']];
    }

    #[DataProvider('unavailableTables')]
    public function test_dashboard_returns_generic_json_on_storage_failure(string $table): void
    {
        config(['app.debug' => true]);
        [$client] = $this->reservationContext();
        $headers = $this->bearer($client);
        DB::statement('DROP TABLE '.$table);
        $this->get(self::DASHBOARD, $headers + ['Accept' => 'text/html'])->assertStatus(503)
            ->assertHeader('Content-Type', 'application/json')->assertJsonPath('error.code', 'temporarily_unavailable')
            ->assertJsonMissingPath('data')->assertDontSee('SQLSTATE')->assertDontSee($table);
    }

    public function test_new_reads_respect_existing_rate_limits_and_api_inventory(): void
    {
        $this->assertCurrentApiInventory();
        $service = $this->service($this->account(RoleName::Provider));
        foreach (range(1, 120) as $i) {
            $this->get('/api/v1/categories')->assertOk();
        }
        $this->get('/api/v1/providers/'.$service->provider_profile_id.'/services')->assertStatus(429)
            ->assertHeader('Retry-After')->assertJsonPath('error.code', 'rate_limited');
        $headers = $this->bearer($this->account(RoleName::Client));
        foreach (range(1, 120) as $i) {
            $this->get(self::DASHBOARD, $headers)->assertOk();
        }
        $this->get(self::DASHBOARD, $headers)->assertStatus(429)
            ->assertHeader('Retry-After')->assertJsonPath('error.code', 'rate_limited');
    }
}
