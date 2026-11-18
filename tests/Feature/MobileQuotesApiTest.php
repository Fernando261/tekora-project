<?php

namespace Tests\Feature;

use App\Enums\InquiryStatus;
use App\Enums\RoleName;
use App\Payments\PricingStore;
use App\Payments\ServicePricing;
use App\Payments\SyntheticQuotes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\MobileOperationsFixtures;
use Tests\TestCase;

class MobileQuotesApiTest extends TestCase
{
    use MobileOperationsFixtures;

    private function priced(): array
    {
        [$client, $provider, $inquiry] = $this->reservationContext();
        app(ServicePricing::class)->publish($provider, $inquiry->service_id, ['amount_cents' => 9999, 'expected_version' => 0, 'idempotency_key' => (string) Str::uuid()]);

        return [$client, $provider, $inquiry];
    }

    public function test_mobile_quote_flow_is_explicit_private_and_does_not_create_a_reservation_or_payment(): void
    {
        [$client, , $inquiry] = $this->priced();
        $headers = $this->bearer($client);
        $this->getJson('/api/v1/services/'.$inquiry->service_id)->assertOk()->assertJsonPath('data.price.amount.total_cents', 9999)
            ->assertJsonPath('data.price.amount.commission_cents', 1500)->assertJsonPath('data.price.amount.provider_cents', 8499);
        $issue = '/api/v1/inquiries/'.$inquiry->id.'/quotes';
        $first = $this->postJson($issue, ['idempotency_key' => (string) Str::uuid()], $headers)->assertOk();
        $quote = $first->json('data.id');
        $first->assertJsonPath('data.amount.total_cents', 9999)->assertJsonPath('data.amount.commission_cents', 1500)
            ->assertJsonPath('data.amount.provider_cents', 8499)->assertJsonPath('data.amount.commission_included', true)
            ->assertJsonPath('data.acceptance.state', 'pending')->assertJsonPath('data.synthetic', true);
        $this->getJson($issue, $headers)->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $quote);
        $this->getJson('/api/v1/quotes/'.$quote, $headers)->assertOk()->assertJsonPath('data.id', $quote);
        $this->getJson('/api/v1/quotes/'.$quote.'/acceptance', $headers)->assertOk()->assertJsonPath('data.state', 'pending');
        $this->postJson('/api/v1/quotes/'.$quote.'/accept', ['acknowledge' => true], $headers)->assertOk()->assertJsonPath('data.acceptance.state', 'accepted');
        $this->getJson('/api/v1/quotes/'.$quote.'/acceptance', $headers)->assertOk()->assertJsonPath('data.state', 'accepted');
        $this->assertDatabaseCount('service_quotes', 1);
        $this->assertDatabaseCount('quote_acceptances', 1);
        $this->assertDatabaseCount('service_reservations', 0);
        $this->assertDatabaseCount('reservation_holds', 0);
        foreach (['payments', 'payment_attempts', 'payment_intents', 'payment_receipts'] as $table) {
            $this->assertFalse(DB::getSchemaBuilder()->hasTable($table));
        }
        foreach (['idempotency_key', 'created_by', 'password', 'email', $client->email] as $secret) {
            $this->assertStringNotContainsString($secret, $first->getContent());
        }
        $this->integrity();
    }

    public function test_web_and_mobile_share_issuance_acceptance_and_original_price_on_retries(): void
    {
        [$client, $provider, $inquiry] = $this->priced();
        $key = (string) Str::uuid();
        $this->actingAs($client, 'web')->post(route('quotes.issue', $inquiry), ['idempotency_key' => $key])->assertRedirect();
        $quote = app(PricingStore::class)->quoteByKey($client->id, $key);
        app(ServicePricing::class)->publish($provider, $inquiry->service_id, ['amount_cents' => 200000, 'expected_version' => 1, 'idempotency_key' => (string) Str::uuid()]);
        $headers = $this->bearer($client);
        $this->postJson('/api/v1/inquiries/'.$inquiry->id.'/quotes', ['idempotency_key' => strtoupper($key)], $headers)
            ->assertOk()->assertJsonPath('data.id', $quote->id)->assertJsonPath('data.amount.total_cents', 9999);
        $this->postJson('/api/v1/quotes/'.$quote->id.'/accept', ['acknowledge' => true], $headers)->assertOk();
        $before = DB::table('quote_acceptances')->get()->toJson();
        $this->travelTo($quote->expiresAt->modify('+1 day'));
        $this->actingAs($client, 'web')->post(route('quotes.accept', $quote->id), ['acknowledge' => 1])->assertRedirect();
        $this->getJson('/api/v1/quotes/'.$quote->id, $this->bearer($client))->assertOk()
            ->assertJsonPath('data.acceptance.state', 'accepted')->assertJsonPath('data.acceptance_window_expired', true)
            ->assertJsonPath('data.amount.total_cents', 9999);
        $this->assertSame($before, DB::table('quote_acceptances')->get()->toJson());
        $this->assertDatabaseCount('service_quotes', 1);
        $this->getJson('/api/v1/services/'.$inquiry->service_id)->assertOk()->assertJsonPath('data.price.amount.total_cents', 200000);
        $this->integrity();
    }

    public static function invalidConsent(): array
    {
        return [[[]], [['acknowledge' => false]], [['acknowledge' => []]], [['acknowledge' => 'not accepted']],
            [['acknowledge' => true, 'total_cents' => 1]], [['acknowledge' => true, 'payment_status' => 'authorized']],
            [['acknowledge' => true, 'client_user_id' => 999]], [['acknowledge' => true, 'idempotency_key' => 'invented']]];
    }

    #[DataProvider('invalidConsent')]
    public function test_consent_and_whitelist_cannot_be_bypassed(array $body): void
    {
        [$client, , $inquiry] = $this->priced();
        $quote = app(SyntheticQuotes::class)->issue($client, $inquiry->id, ['idempotency_key' => (string) Str::uuid()]);
        $this->postJson('/api/v1/quotes/'.$quote->id.'/accept', $body, $this->bearer($client))->assertUnprocessable()->assertJsonPath('error.code', 'validation_failed');
        $this->assertDatabaseCount('quote_acceptances', 0);
    }

    public function test_foreign_and_missing_ids_are_hidden_before_validation_and_list_is_scoped(): void
    {
        [$client, $provider, $inquiry] = $this->priced();
        $quote = app(SyntheticQuotes::class)->issue($client, $inquiry->id, ['idempotency_key' => (string) Str::uuid()]);
        $other = $this->account(RoleName::Client);
        foreach ([$other, $provider] as $actor) {
            $headers = $this->bearer($actor);
            $this->getJson('/api/v1/inquiries/'.$inquiry->id.'/quotes', $headers)->assertNotFound();
            $this->postJson('/api/v1/inquiries/'.$inquiry->id.'/quotes', ['amount_cents' => []], $headers)->assertNotFound();
            foreach ([$quote->id, (string) Str::uuid()] as $id) {
                $this->getJson('/api/v1/quotes/'.$id, $headers)->assertNotFound();
                $this->postJson('/api/v1/quotes/'.$id.'/accept', ['acknowledge' => []], $headers)->assertNotFound();
                $this->getJson('/api/v1/quotes/'.$id.'/acceptance', $headers)->assertNotFound();
            }
        }
        $otherInquiry = $this->inquiry($other, $provider, InquiryStatus::Accepted);
        app(ServicePricing::class)->publish($provider, $otherInquiry->service_id, ['amount_cents' => 100, 'expected_version' => 0, 'idempotency_key' => (string) Str::uuid()]);
        app(SyntheticQuotes::class)->issue($other, $otherInquiry->id, ['idempotency_key' => (string) Str::uuid()]);
        $this->getJson('/api/v1/inquiries/'.$inquiry->id.'/quotes', $this->bearer($client))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $quote->id);
        $this->assertDatabaseCount('quote_acceptances', 0);
    }

    public function test_authentication_requires_current_mobile_client_token_not_web_session(): void
    {
        [$client, , $inquiry] = $this->priced();
        $url = '/api/v1/inquiries/'.$inquiry->id.'/quotes';
        $this->get($url, ['Accept' => 'text/html'])->assertUnauthorized()->assertHeader('Content-Type', 'application/json');
        $this->actingAs($client, 'web')->postJson($url, ['idempotency_key' => (string) Str::uuid()])->assertUnauthorized();
        $token = $client->createToken('mobile:wrong', ['other:read'])->plainTextToken;
        \Illuminate\Support\Facades\Auth::forgetGuards();
        $this->getJson($url, ['Authorization' => 'Bearer '.$token])->assertForbidden();
        $client->roles()->detach();
        $this->getJson($url, $this->bearer($client))->assertForbidden();
        $this->assertDatabaseCount('service_quotes', 0);
    }

    public function test_emission_pagination_idempotency_and_unknown_fields_are_bounded(): void
    {
        [$client, $provider, $inquiry] = $this->priced();
        $headers = $this->bearer($client);
        $url = '/api/v1/inquiries/'.$inquiry->id.'/quotes';
        $ids = [];
        foreach (range(1, 3) as $n) {
            $ids[] = $this->postJson($url, ['idempotency_key' => (string) Str::uuid()], $headers)->assertOk()->json('data.id');
        }
        rsort($ids);
        $this->getJson($url.'?per_page=1', $headers)->assertOk()->assertJsonPath('data.0.id', $ids[0])->assertJsonPath('meta.total', 3);
        $this->getJson($url.'?per_page=1&page=2', $headers)->assertOk()->assertJsonPath('data.0.id', $ids[1]);
        foreach (['page=0', 'per_page=51', 'page[]=1', 'include=client', 'client_user_id=999'] as $filter) {
            $this->getJson($url.'?'.$filter, $headers)->assertUnprocessable();
        }
        foreach ([[], ['idempotency_key' => []], ['idempotency_key' => 'bad'], ['idempotency_key' => (string) Str::uuid(), 'amount_cents' => 1]] as $bad) {
            $this->postJson($url, $bad, $headers)->assertUnprocessable();
        }
        $existing = app(PricingStore::class)->quote($ids[0]);
        $other = $this->inquiry($client, $provider, InquiryStatus::Accepted);
        $this->postJson('/api/v1/inquiries/'.$other->id.'/quotes', ['idempotency_key' => $existing->idempotencyKey], $headers)->assertConflict()->assertJsonPath('error.code', 'conflict');
        $this->assertDatabaseCount('service_quotes', 3);
    }

    public static function acceptanceBoundary(): array
    {
        return [[-1, 200, 'accepted'], [0, 409, 'expired'], [1, 409, 'expired']];
    }

    #[DataProvider('acceptanceBoundary')]
    public function test_first_acceptance_respects_exact_expiry(int $offset, int $status, string $state): void
    {
        [$client, , $inquiry] = $this->priced();
        $quote = app(SyntheticQuotes::class)->issue($client, $inquiry->id, ['idempotency_key' => (string) Str::uuid()]);
        $this->travelTo(\Carbon\CarbonImmutable::instance($quote->expiresAt)->addMicroseconds($offset));
        $headers = $this->bearer($client);
        $this->postJson('/api/v1/quotes/'.$quote->id.'/accept', ['acknowledge' => true], $headers)->assertStatus($status);
        $this->getJson('/api/v1/quotes/'.$quote->id.'/acceptance', $headers)->assertOk()->assertJsonPath('data.state', $state);
        $this->integrity();
    }

    public function test_unpriced_or_unaccepted_inquiry_and_paused_service_follow_web_rules(): void
    {
        [$client, , $inquiry] = $this->reservationContext();
        $headers = $this->bearer($client);
        $this->postJson('/api/v1/inquiries/'.$inquiry->id.'/quotes', ['idempotency_key' => (string) Str::uuid()], $headers)->assertConflict();
        $this->getJson('/api/v1/services/'.$inquiry->service_id)->assertOk()->assertJsonPath('data.price', null);
        [$client, , $inquiry] = $this->priced();
        $headers = $this->bearer($client);
        $quote = app(SyntheticQuotes::class)->issue($client, $inquiry->id, ['idempotency_key' => (string) Str::uuid()]);
        $inquiry->service->update(['status' => 'paused']);
        $this->postJson('/api/v1/quotes/'.$quote->id.'/accept', ['acknowledge' => true], $headers)->assertNotFound();
        $this->getJson('/api/v1/quotes/'.$quote->id, $headers)->assertOk()->assertJsonPath('data.acceptance.state', 'pending');
        $this->assertDatabaseCount('quote_acceptances', 0);
    }

    public function test_storage_failure_rolls_back_and_retry_uses_same_key_without_leaking_sql(): void
    {
        [$client, , $inquiry] = $this->priced();
        $headers = $this->bearer($client);
        $key = (string) Str::uuid();
        config(['app.debug' => true]);
        DB::unprepared("CREATE TRIGGER b3_test_failure BEFORE INSERT ON service_quotes BEGIN SELECT RAISE(ABORT,'PRIVATE_B3_SQL'); END");
        $response = $this->postJson('/api/v1/inquiries/'.$inquiry->id.'/quotes', ['idempotency_key' => $key], $headers)->assertStatus(503)->assertJsonPath('error.code', 'temporarily_unavailable');
        $this->assertStringNotContainsString('PRIVATE_B3_SQL', $response->getContent());
        $this->assertDatabaseCount('service_quotes', 0);
        DB::unprepared('DROP TRIGGER b3_test_failure');
        $this->postJson('/api/v1/inquiries/'.$inquiry->id.'/quotes', ['idempotency_key' => $key], $headers)->assertOk();
        $this->assertDatabaseCount('service_quotes', 1);
        $this->integrity();
    }

    public function test_quote_writes_are_rate_limited_even_when_they_are_idempotent_replays(): void
    {
        [$client, , $inquiry] = $this->priced();
        $headers = $this->bearer($client);
        $body = ['idempotency_key' => (string) Str::uuid()];
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/v1/inquiries/'.$inquiry->id.'/quotes', $body, $headers)->assertOk();
        }
        $this->postJson('/api/v1/inquiries/'.$inquiry->id.'/quotes', $body, $headers)->assertStatus(429)->assertHeader('Retry-After');
        $this->assertDatabaseCount('service_quotes', 1);
    }

    public function test_mobile_commercial_journey_from_quote_to_hold_and_confirmation_needs_no_new_web_logic(): void
    {
        [$client, $provider, $inquiry] = $this->priced();
        $headers = $this->bearer($client);
        $quote = $this->postJson('/api/v1/inquiries/'.$inquiry->id.'/quotes', ['idempotency_key' => (string) Str::uuid()], $headers)->assertOk()->json('data.id');
        $this->postJson('/api/v1/quotes/'.$quote.'/accept', ['acknowledge' => true], $headers)->assertOk();
        $this->getJson('/api/v1/inquiries/'.$inquiry->id.'/reservation-options', $headers)->assertOk()
            ->assertJsonPath('data.commercial.accepted_quotes.0.id', $quote)->assertJsonPath('data.commercial.hold_seconds', 900);
        $reservation = $this->postJson('/api/v1/inquiries/'.$inquiry->id.'/reservations', $this->reservationData(['quote_id' => $quote]), $headers)
            ->assertCreated()->json('data.id');
        $decision = ['version' => 1, 'idempotency_key' => (string) Str::uuid()];
        $this->actingAs($provider, 'web')->patch(route('reservations.confirm', $reservation), $decision)->assertRedirect();
        $headers = $this->bearer($client);
        $this->getJson('/api/v1/reservations/'.$reservation.'/checkout-context', $headers)->assertOk()
            ->assertJsonPath('data.quote.id', $quote)->assertJsonPath('data.amount.total_cents', 9999)
            ->assertJsonPath('data.future_payment.can_start', false)->assertJsonPath('data.reservation.status', 'held');
        $commit = ['version' => 1, 'idempotency_key' => (string) Str::uuid()];
        $this->patchJson('/api/v1/reservations/'.$reservation.'/commit', $commit, $headers)->assertOk()->assertJsonPath('data.status', 'confirmed');
        $before = $this->reservationRows(['service_reservations', 'reservation_events', 'reservation_action_receipts', 'reservation_hold_events']);
        $this->travel(901)->seconds();
        $this->patchJson('/api/v1/reservations/'.$reservation.'/commit', $commit, $headers)->assertOk()->assertJsonPath('data.version', 2);
        $this->assertSame($before, $this->reservationRows(['service_reservations', 'reservation_events', 'reservation_action_receipts', 'reservation_hold_events']));
        $this->assertDatabaseCount('service_quotes', 1);
        $this->assertDatabaseCount('quote_acceptances', 1);
        $this->integrity();
    }

    public function test_new_quote_requires_an_accepted_inquiry_even_when_a_service_has_a_price(): void
    {
        [$client, $provider] = $this->priced();
        $pending = $this->inquiry($client, $provider, InquiryStatus::Pending);
        app(ServicePricing::class)->publish($provider, $pending->service_id, ['amount_cents' => 100, 'expected_version' => 0, 'idempotency_key' => (string) Str::uuid()]);
        $this->postJson('/api/v1/inquiries/'.$pending->id.'/quotes', ['idempotency_key' => (string) Str::uuid()], $this->bearer($client))->assertConflict();
        $this->assertDatabaseCount('service_quotes', 0);
    }

    private function integrity(): void
    {
        $this->assertSame('ok', DB::selectOne('PRAGMA integrity_check')->integrity_check);
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
        $this->assertSame(1, (int) DB::selectOne('PRAGMA foreign_keys')->foreign_keys);
    }
}
