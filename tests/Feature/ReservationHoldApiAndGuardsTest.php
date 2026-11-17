<?php

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Enums\RoleName;
use App\Payments\SyntheticQuotes;
use App\Services\ReservationWorkflow;
use App\Support\BookingClock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\SlotHoldFixtures;
use Tests\TestCase;

class ReservationHoldApiAndGuardsTest extends TestCase
{
    use SlotHoldFixtures;

    public function test_sqlite_clock_preserves_microseconds_beyond_32_bits(): void
    {
        $this->travelTo(now('UTC')->setDate(2030, 1, 1)->setTime(12, 0, 0, 123456));
        $row = DB::selectOne('SELECT tekora_booking_now_us() AS raw, CAST(tekora_booking_now_us() AS INTEGER) AS n, typeof(CAST(tekora_booking_now_us() AS INTEGER)) AS kind');
        $this->assertGreaterThan(2147483647, BookingClock::micros());
        $this->assertSame((string) BookingClock::micros(), $row->raw);
        $this->assertSame(BookingClock::micros(), $row->n);
        $this->assertSame('integer', $row->kind);
        $this->assertSame(123456, $row->n % 1000000);
    }

    public function test_mobile_client_can_discover_selected_quote_confirm_hold_and_cancel_without_bypass(): void
    {
        [$client, $provider, $inquiry, $quote, $reservation] = $this->pricedProposal();
        app(ReservationWorkflow::class)->decide($provider, $reservation->id, 'confirm', $this->holdInput());
        $headers = $this->bearer($client);
        $url = '/api/v1/reservations/'.$reservation->id;
        $this->getJson($url, $headers)->assertOk()->assertJsonPath('data.status', 'held')->assertJsonPath('data.quote_id', $quote->id)
            ->assertJsonPath('data.hold.state', 'held')->assertJsonPath('data.hold.remaining_seconds', 900)->assertJsonPath('data.commercial_protocol', 'quoted-slot-hold-v1');
        $this->getJson('/api/v1/reservations?status=held', $headers)->assertOk()->assertJsonCount(1, 'data');
        $key = $this->holdInput();
        $this->patchJson($url.'/commit', $key, $headers)->assertOk()->assertJsonPath('data.status', 'confirmed')->assertJsonPath('data.hold.state', 'committed');
        $this->patchJson($url.'/commit', $key, $headers)->assertOk()->assertJsonPath('data.version', 2);
        $this->patchJson($url.'/cancel', $this->holdInput(['version' => 2]), $headers)->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertHoldIntegrity();
    }

    public function test_mobile_options_surface_accepted_quote_ids_and_proposal_retry_checks_quote_identity(): void
    {
        [$client, $provider, $inquiry, $quote, $reservation, $input] = $this->pricedProposal();
        $headers = $this->bearer($client);
        $this->patchJson('/api/v1/reservations/'.$reservation->id.'/cancel', $this->holdInput(), $headers)->assertOk();
        $this->getJson('/api/v1/inquiries/'.$inquiry->id.'/reservation-options', $headers)->assertOk()
            ->assertJsonPath('data.commercial.hold_seconds', 900)->assertJsonPath('data.commercial.accepted_quotes.0.id', $quote->id);
        $this->postJson('/api/v1/inquiries/'.$inquiry->id.'/reservations', $input, $headers)->assertOk()->assertJsonPath('data.id', $reservation->id);
        $second = app(SyntheticQuotes::class)->issue($client, $inquiry->id, ['idempotency_key' => (string) Str::uuid()]);
        app(SyntheticQuotes::class)->accept($client, $second->id);
        $this->postJson('/api/v1/inquiries/'.$inquiry->id.'/reservations', array_replace($input, ['quote_id' => $second->id]), $headers)->assertConflict();
        $this->assertDatabaseCount('service_reservations', 1);
    }

    public function test_mobile_cannot_confirm_before_provider_or_after_expiry_and_foreign_id_is_hidden(): void
    {
        [$client, $provider, , , $reservation] = $this->pricedProposal();
        $url = '/api/v1/reservations/'.$reservation->id;
        $this->patchJson($url.'/commit', $this->holdInput(), $this->bearer($client))->assertConflict();
        $this->patchJson($url.'/commit', ['version' => []], $this->bearer($this->account(RoleName::Client)))->assertNotFound();
        app(ReservationWorkflow::class)->decide($provider, $reservation->id, 'confirm', $this->holdInput());
        $this->travel(900)->seconds();
        $this->patchJson($url.'/commit', $this->holdInput(), $this->bearer($client))->assertConflict();
        $this->getJson($url, $this->bearer($client))->assertOk()->assertJsonPath('data.status', 'hold_expired');
        $this->assertHoldIntegrity();
    }

    public function test_legacy_service_client_commit_endpoint_never_skips_provider_confirmation(): void
    {
        [$client, , $inquiry] = $this->reservationContext();
        $reservation = $this->proposeReservation($inquiry);
        $this->patchJson('/api/v1/reservations/'.$reservation->id.'/commit', $this->holdInput(), $this->bearer($client))->assertConflict();
        $this->assertSame(ReservationStatus::Requested, $reservation->fresh()->status);
        $this->assertDatabaseCount('reservation_holds', 0);
    }

    public static function invalidHoldMutation(): array
    {
        return [['accepted_at_us', 1], ['expires_at_us', 900000001], ['accepted_by', 9999], ['state', 'committed'], ['state', 'released'], ['version', 9], ['service_reservation_id', 9999]];
    }

    #[DataProvider('invalidHoldMutation')]
    public function test_sql_cannot_rewrite_or_force_a_hold_transition(string $field, mixed $value): void
    {
        [, $provider, , , $reservation] = $this->pricedProposal();
        app(ReservationWorkflow::class)->decide($provider, $reservation->id, 'confirm', $this->holdInput());
        $before = DB::table('reservation_holds')->get()->toJson();
        try {
            DB::table('reservation_holds')->update([$field => $value]);
            $this->fail('La mutación debe ser rechazada.');
        } catch (\Illuminate\Database\QueryException $error) {
            $this->assertSame(19, $error->errorInfo[1] & 255);
        }
        $this->assertSame($before, DB::table('reservation_holds')->get()->toJson());
        $this->assertHoldIntegrity();
    }

    public static function immutableTables(): array
    {
        return [['reservation_holds', 'delete'], ['reservation_hold_events', 'delete'], ['reservation_hold_events', 'update'], ['reservation_action_receipts', 'delete'], ['reservation_action_receipts', 'update']];
    }

    #[DataProvider('immutableTables')]
    public function test_history_and_receipts_are_append_only(string $table, string $action): void
    {
        [, $provider, , , $reservation] = $this->pricedProposal();
        app(ReservationWorkflow::class)->decide($provider, $reservation->id, 'confirm', $this->holdInput());
        $before = DB::table($table)->get()->toJson();
        try {
            if ($action === 'delete') {
                DB::table($table)->delete();
            } elseif ($table === 'reservation_hold_events') {
                DB::table($table)->update(['to_state' => 'expired']);
            } else {
                DB::table($table)->update(['action' => 'cancel']);
            }
            $this->fail('Historial mutable.');
        } catch (\Illuminate\Database\QueryException $error) {
            $this->assertSame(19, $error->errorInfo[1] & 255);
        }
        $this->assertSame($before, DB::table($table)->get()->toJson());
        $this->assertHoldIntegrity();
    }

    public function test_priced_sql_proposals_require_binding_and_confirmation_requires_a_live_hold_and_client(): void
    {
        [$client, $provider, $inquiry, $quote, $reservation] = $this->pricedProposal();
        $before = $this->reservationRows(['service_reservations', 'reservation_events', 'reservation_holds']);
        foreach ([$client->id, $provider->id] as $actor) {
            try {
                DB::table('service_reservations')->where('id', $reservation->id)->update(['status' => 'confirmed', 'version' => 2, 'last_actor_user_id' => $actor, 'last_reason' => 'confirm']);
                $this->fail('SQL omite la retención.');
            } catch (\Illuminate\Database\QueryException $error) {
                $this->assertSame(19, $error->errorInfo[1] & 255);
            }
        }
        try {
            DB::table('service_reservations')->where('id', $reservation->id)->update(['quote_id' => null]);
            $this->fail('Eliminar vínculo comercial.');
        } catch (\Illuminate\Database\QueryException $error) {
            $this->assertSame(19, $error->errorInfo[1] & 255);
        }
        $this->assertSame($before, $this->reservationRows(['service_reservations', 'reservation_events', 'reservation_holds']));
        $this->assertHoldIntegrity();
    }

    public function test_quote_acceptance_ttl_is_independent_and_899999999_microseconds_are_valid(): void
    {
        config(['quotes.lifetime_seconds' => 1]);
        [$client, $provider, , $quote, $reservation] = $this->pricedProposal();
        $this->travel(2)->seconds();
        app(ReservationWorkflow::class)->decide($provider, $reservation->id, 'confirm', $this->holdInput());
        $hold = $reservation->fresh()->hold;
        $this->assertGreaterThan($quote->expiresAt->getTimestamp(), intdiv($hold->accepted_at_us, 1000000));
        $this->travelTo(BookingClock::date($hold->accepted_at_us + 899999999));
        $result = app(ReservationWorkflow::class)->decide($client, $reservation->id, 'commit', $this->holdInput());
        $this->assertSame(ReservationStatus::Confirmed, $result->status);
        $this->assertHoldIntegrity();
    }

    public function test_valid_hold_conversion_keeps_completion_reviews_and_inquiry_rule(): void
    {
        [$client, $provider, $inquiry, , $reservation] = $this->pricedProposal();
        app(ReservationWorkflow::class)->decide($provider, $reservation->id, 'confirm', $this->holdInput());
        app(ReservationWorkflow::class)->decide($client, $reservation->id, 'commit', $this->holdInput());
        $this->travelTo(\Carbon\Carbon::parse($reservation->ends_at_utc, 'UTC'));
        app(ReservationWorkflow::class)->decide($provider, $reservation->id, 'complete', $this->holdInput(['version' => 2]));
        $this->actingAs($provider)->patch(route('inquiries.complete', $inquiry))->assertRedirect();
        $this->actingAs($client)->post(route('reviews.store', $reservation), ['rating' => 5])->assertRedirect();
        $this->assertSame('completed', $inquiry->fresh()->status->value);
        $this->assertDatabaseCount('service_reviews', 1);
        $this->assertHoldIntegrity();
    }
}
