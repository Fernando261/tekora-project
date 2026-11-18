<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Payments\PricingStore;
use App\Payments\ServicePricing;
use App\Services\ReservationWorkflow;
use App\Support\BookingClock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\SlotHoldFixtures;
use Tests\TestCase;

class CheckoutContextApiTest extends TestCase
{
    use SlotHoldFixtures;

    private function snapshot(): array
    {
        return $this->reservationRows(['service_quotes', 'quote_acceptances', 'service_reservations', 'reservation_events',
            'reservation_holds', 'reservation_hold_events', 'reservation_hold_clock', 'reservation_action_receipts']);
    }

    public function test_shared_web_mobile_context_is_read_only_and_never_enables_payments(): void
    {
        [$client, $provider, , $quote, $reservation] = $this->pricedProposal();
        app(ReservationWorkflow::class)->decide($provider, $reservation->id, 'confirm', $this->holdInput());
        $before = $this->snapshot();
        $api = $this->getJson('/api/v1/reservations/'.$reservation->id.'/checkout-context', $this->bearer($client))->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('data.hold.remaining_seconds', 900)
            ->assertJsonPath('data.hold.hold_seconds', 900)->assertJsonPath('data.reservation.status', 'held')
            ->assertJsonPath('data.quote.id', $quote->id)->assertJsonPath('data.amount.total_cents', 100000)
            ->assertJsonPath('data.amount.commission_cents', 15000)->assertJsonPath('data.amount.provider_cents', 85000)
            ->assertJsonPath('data.future_payment.commercial_eligible', true)->assertJsonPath('data.future_payment.can_start', false)
            ->assertJsonPath('data.future_payment.enabled', false)->assertJsonPath('data.future_payment.blocking_reasons', ['payments_not_enabled'])
            ->assertJsonPath('data.confirmation_policy.requires_verified_payment', false);
        $web = $this->actingAs($client, 'web')->getJson(route('reservations.checkout-context', $reservation))->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame($api->json(), $web->json());
        $this->assertSame($before, $this->snapshot());
        foreach ([$client->email, $provider->email, 'idempotency_key', 'access_token', 'payment_id', 'authorization_id', 'pan', 'cvv'] as $secret) {
            $this->assertStringNotContainsString($secret, $api->getContent());
        }
        // Current confirmation still succeeds without a payment: policy is not activated.
        $this->patchJson('/api/v1/reservations/'.$reservation->id.'/commit', $this->holdInput(), $this->bearer($client))->assertOk()->assertJsonPath('data.status', 'confirmed');
        $this->getJson('/api/v1/reservations/'.$reservation->id.'/checkout-context', $this->bearer($client))->assertOk()->assertJsonPath('data.future_payment.commercial_eligible', false);
        $this->assertHoldIntegrity();
    }

    public static function holdBoundary(): array
    {
        return [[899999999, true, 1, 'held'], [900000000, false, 0, 'expired'], [900000001, false, 0, 'expired']];
    }

    #[DataProvider('holdBoundary')]
    public function test_900_second_boundary_is_observed_without_mutating_or_extending_hold(int $offset, bool $eligible, int $remaining, string $state): void
    {
        [$client, $provider, , , $reservation] = $this->pricedProposal();
        app(ReservationWorkflow::class)->decide($provider, $reservation->id, 'confirm', $this->holdInput());
        $hold = $reservation->fresh()->hold;
        $this->travelTo(BookingClock::date($hold->accepted_at_us + $offset));
        $before = $this->snapshot();
        $this->getJson('/api/v1/reservations/'.$reservation->id.'/checkout-context', $this->bearer($client))->assertOk()
            ->assertJsonPath('data.hold.remaining_seconds', $remaining)->assertJsonPath('data.hold.state', $state)
            ->assertJsonPath('data.hold.stored_state', 'held')->assertJsonPath('data.server_now', BookingClock::date($hold->accepted_at_us + $offset)->toISOString())
            ->assertJsonPath('data.future_payment.commercial_eligible', $eligible)->assertJsonPath('data.future_payment.can_start', false);
        $this->assertSame($before, $this->snapshot());
        $this->assertSame(BookingClock::HOLD_MICROSECONDS, $hold->expires_at_us - $hold->accepted_at_us);
        if (! $eligible) {
            $this->patchJson('/api/v1/reservations/'.$reservation->id.'/commit', $this->holdInput(), $this->bearer($client))->assertConflict();
        }
        $this->assertHoldIntegrity();
    }

    public function test_accepted_quote_expiry_and_later_price_publication_or_schedule_edits_do_not_displace_a_hold(): void
    {
        config(['quotes.lifetime_seconds' => 1]);
        [$client, $provider, $inquiry, , $reservation] = $this->pricedProposal();
        app(ReservationWorkflow::class)->decide($provider, $reservation->id, 'confirm', $this->holdInput());
        $this->travel(2)->seconds();
        app(ServicePricing::class)->publish($provider, $inquiry->service_id, ['amount_cents' => 200000, 'expected_version' => 1, 'idempotency_key' => (string) Str::uuid()]);
        $originalTitle = $inquiry->service_title;
        $inquiry->service->update(['status' => 'paused', 'title' => 'B3_RENAMED_CATALOG_TITLE']);
        $this->publishSchedule($provider, 'America/Mexico_City', 1, ['blocked_dates' => [$reservation->local_date]]);
        $this->getJson('/api/v1/reservations/'.$reservation->id.'/checkout-context', $this->bearer($client))->assertOk()
            ->assertJsonPath('data.quote.acceptance_window_expired', true)->assertJsonPath('data.quote.acceptance.state', 'accepted')
            ->assertJsonPath('data.amount.total_cents', 100000)->assertJsonPath('data.future_payment.commercial_eligible', true)
            ->assertJsonPath('data.service.title', $originalTitle);
        $this->patchJson('/api/v1/reservations/'.$reservation->id.'/commit', $this->holdInput(), $this->bearer($client))->assertOk();
        $this->assertHoldIntegrity();
    }

    public function test_unstarted_released_and_legacy_contexts_never_offer_payment(): void
    {
        [$client, $provider, , , $reservation] = $this->pricedProposal();
        $url = '/api/v1/reservations/'.$reservation->id.'/checkout-context';
        $headers = $this->bearer($client);
        $this->getJson($url, $headers)->assertOk()->assertJsonPath('data.hold', null)->assertJsonPath('data.future_payment.commercial_eligible', false);
        app(ReservationWorkflow::class)->decide($provider, $reservation->id, 'confirm', $this->holdInput());
        app(ReservationWorkflow::class)->decide($client, $reservation->id, 'cancel', $this->holdInput());
        $this->getJson($url, $headers)->assertOk()->assertJsonPath('data.hold.state', 'released')->assertJsonPath('data.future_payment.commercial_eligible', false);
        [$other, , $inquiry] = $this->reservationContext();
        $legacy = $this->proposeReservation($inquiry);
        $this->getJson('/api/v1/reservations/'.$legacy->id.'/checkout-context', $this->bearer($other))->assertOk()
            ->assertJsonPath('data.amount', null)->assertJsonPath('data.quote', null)->assertJsonPath('data.future_payment.enabled', false)
            ->assertJsonPath('data.confirmation_policy.protocol', 'legacy');
    }

    public function test_context_owner_and_input_allowlist_cannot_be_bypassed(): void
    {
        [$client, $provider, , , $reservation] = $this->pricedProposal();
        $api = '/api/v1/reservations/'.$reservation->id.'/checkout-context';
        $this->getJson($api)->assertUnauthorized();
        foreach ([$provider, $this->account(RoleName::Client)] as $actor) {
            $this->getJson($api.'?amount_cents=1', $this->bearer($actor))->assertNotFound();
            $this->actingAs($actor, 'web')->getJson(route('reservations.checkout-context', $reservation))->assertNotFound();
        }
        $before = $this->snapshot();
        foreach (['amount_cents=1', 'payment_verified=true', 'hold_seconds=1800', 'quote_id=forged', 'expires_at=2100-01-01'] as $filter) {
            $this->getJson($api.'?'.$filter, $this->bearer($client))->assertUnprocessable();
            $this->actingAs($client, 'web')->getJson(route('reservations.checkout-context', $reservation).'?'.$filter)->assertUnprocessable();
        }
        $this->postJson($api, [], $this->bearer($client))->assertStatus(405);
        $this->postJson('/api/v1/payments', [], $this->bearer($client))->assertNotFound();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_corrupt_quote_binding_returns_safe_503_instead_of_an_eligible_context(): void
    {
        [$client, $provider, , $quote, $reservation] = $this->pricedProposal();
        $wrong = new \App\Payments\SyntheticQuote($quote->id, $quote->inquiryId, $quote->serviceId, $quote->providerProfileId, $client->id + 100,
            $quote->price, $quote->idempotencyKey, $quote->issuedAt, $quote->expiresAt);
        $this->mock(PricingStore::class)->shouldReceive('quote')->once()->with($quote->id)->andReturn($wrong);
        config(['app.debug' => true]);
        $response = $this->getJson('/api/v1/reservations/'.$reservation->id.'/checkout-context', $this->bearer($client))->assertStatus(503)
            ->assertJsonPath('error.code', 'temporarily_unavailable');
        $response->assertJsonMissingPath('data');
        $this->assertStringNotContainsString('LogicException', $response->getContent());
        $this->assertDatabaseCount('reservation_holds', 0);
    }

    public function test_revoked_provider_permission_blocks_advisory_eligibility_and_current_commit(): void
    {
        [$client, $provider, , , $reservation] = $this->pricedProposal();
        app(ReservationWorkflow::class)->decide($provider, $reservation->id, 'confirm', $this->holdInput());
        $provider->roles()->detach();
        $response = $this->getJson('/api/v1/reservations/'.$reservation->id.'/checkout-context', $this->bearer($client))->assertOk()
            ->assertJsonPath('data.future_payment.commercial_eligible', false);
        $this->assertContains('participants_unavailable', $response->json('data.future_payment.blocking_reasons'));
        $this->patchJson('/api/v1/reservations/'.$reservation->id.'/commit', $this->holdInput(), $this->bearer($client))->assertConflict();
        $this->assertHoldIntegrity();
    }

    public function test_missing_clock_is_unknown_and_clock_rollback_does_not_extend_a_hold(): void
    {
        [$client, $provider, , , $reservation] = $this->pricedProposal();
        app(ReservationWorkflow::class)->decide($provider, $reservation->id, 'confirm', $this->holdInput());
        $hold = $reservation->fresh()->hold;
        $this->travelTo(BookingClock::date($hold->accepted_at_us - 1000000));
        $this->getJson('/api/v1/reservations/'.$reservation->id.'/checkout-context', $this->bearer($client))->assertOk()
            ->assertJsonPath('data.server_now', BookingClock::date($hold->accepted_at_us)->toISOString())->assertJsonPath('data.hold.remaining_seconds', 900);
        // Fault injection in this disposable in-memory database only.
        DB::unprepared('DROP TABLE reservation_hold_clock');
        $this->getJson('/api/v1/reservations/'.$reservation->id.'/checkout-context', $this->bearer($client))->assertStatus(503)
            ->assertJsonPath('error.code', 'temporarily_unavailable')->assertJsonMissingPath('data');
    }
}
