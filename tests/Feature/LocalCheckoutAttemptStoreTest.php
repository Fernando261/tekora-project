<?php

namespace Tests\Feature;

use App\Payments\CheckoutContext;
use App\Payments\LocalCheckoutAttemptStore;
use App\Services\ReservationWorkflow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\SlotHoldFixtures;
use Tests\TestCase;

class LocalCheckoutAttemptStoreTest extends TestCase
{
    use SlotHoldFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        // Isolated in-memory fixture, never an application migration.
        DB::statement("CREATE TABLE synthetic_checkout_attempts (
            reservation_id INTEGER PRIMARY KEY,
            client_user_id INTEGER NOT NULL,
            provider_user_id INTEGER NOT NULL,
            quote_id TEXT NOT NULL,
            amount_cents INTEGER NOT NULL CHECK(amount_cents > 0),
            method TEXT NOT NULL CHECK(method IN ('card','cash')),
            status TEXT NOT NULL CHECK(status IN ('test_approved','declined','temporary_failure','awaiting_cash','cash_received')),
            idempotency_key TEXT NOT NULL,
            reference TEXT NOT NULL UNIQUE,
            attempted_at TEXT NOT NULL,
            received_at TEXT NULL
        )");
    }

    private function heldContext(): array
    {
        [$client, $provider, , $quote, $reservation] = $this->pricedProposal();
        app(ReservationWorkflow::class)->decide($provider, $reservation->id, 'confirm', $this->holdInput());

        return [$client, $provider, $quote, $reservation,
            app(CheckoutContext::class)->forReservation($client, $reservation->id)];
    }

    public function test_card_results_use_frozen_quote_and_same_idempotency_key_reuses_one_attempt(): void
    {
        [$client, , $quote, $reservation, $ctx] = $this->heldContext();
        $store = app(LocalCheckoutAttemptStore::class);
        $key = (string) Str::uuid();
        $before = DB::table('service_reservations')->where('id', $reservation->id)->first();
        $first = $store->submit($client, $ctx, 'card', 'test_approved', $key);
        $this->assertSame('test_approved', $first['status']);
        $this->assertSame($quote->id, $first['quote_id']);
        $this->assertSame(100000, (int) $first['amount_cents']);
        $this->assertSame($first, $store->submit($client, $ctx, 'card', 'test_approved', $key));
        $this->assertSame(1, DB::table('synthetic_checkout_attempts')->count());
        $this->assertEquals($before, DB::table('service_reservations')->where('id', $reservation->id)->first());
        $this->assertSame('held', $reservation->fresh()->effectiveStatus()->value);
    }

    public function test_rejected_card_can_retry_but_approved_intent_cannot_be_replaced(): void
    {
        [$client, , , $reservation, $ctx] = $this->heldContext();
        $store = app(LocalCheckoutAttemptStore::class);
        $declined = $store->submit($client, $ctx, 'card', 'declined', (string) Str::uuid());
        $approved = $store->submit($client, $ctx, 'card', 'test_approved', (string) Str::uuid());
        $this->assertNotSame($declined['reference'], $approved['reference']);
        $this->assertSame('test_approved', $store->current($reservation->id)['status']);
        $this->expectException(HttpException::class);
        $store->submit($client, $ctx, 'cash', 'awaiting_cash', (string) Str::uuid());
    }

    public function test_only_actual_provider_can_record_cash_and_no_reservation_is_committed(): void
    {
        [$client, $provider, , $reservation, $ctx] = $this->heldContext();
        $store = app(LocalCheckoutAttemptStore::class);
        $cash = $store->submit($client, $ctx, 'cash', 'awaiting_cash', (string) Str::uuid());
        $this->assertSame('awaiting_cash', $cash['status']);
        try {
            $store->receiveCash($client, $reservation->fresh());
            $this->fail('El cliente no puede registrar efectivo.');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        $received = $store->receiveCash($provider, $reservation->fresh());
        $this->assertSame('cash_received', $received['status']);
        $this->assertSame($received, $store->receiveCash($provider, $reservation->fresh()));
        $this->assertSame('held', $reservation->fresh()->effectiveStatus()->value);
        $this->assertSame(1, DB::table('synthetic_checkout_attempts')->count());
    }

    public function test_cash_receipt_cannot_revive_an_expired_hold(): void
    {
        [$client, $provider, , $reservation, $ctx] = $this->heldContext();
        $store = app(LocalCheckoutAttemptStore::class);
        $store->submit($client, $ctx, 'cash', 'awaiting_cash', (string) Str::uuid());
        $this->travel(900)->seconds();
        try {
            $store->receiveCash($provider, $reservation->fresh());
            $this->fail('Un horario vencido no admite nueva recepción sintética.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $this->assertSame('awaiting_cash', $store->current($reservation->id)['status']);
    }
}
