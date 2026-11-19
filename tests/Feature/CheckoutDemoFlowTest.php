<?php

namespace Tests\Feature;

use App\Demo\CheckoutStore;
use App\Payments\CommercialAmount;
use App\Support\BookingClock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CheckoutDemoFixtures;
use Tests\TestCase;

class CheckoutDemoFlowTest extends TestCase
{
    use CheckoutDemoFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareDemo();
    }

    private function payload(string $method = 'card', string $scenario = 'success'): array
    {
        return ['method' => $method, 'scenario' => $scenario, 'idempotency_key' => (string) Str::uuid()]
            + ($method === 'card' ? ['instrument' => 'debit'] : []);
    }

    public static function methods(): array
    {
        return [['card'], ['cash']];
    }

    #[DataProvider('methods')]
    public function test_completed_results_are_only_demo_and_survive_replay_reload_and_time_expiry(string $method): void
    {
        [$demo] = $this->customer();
        $body = $this->payload($method);
        $queries = [];
        DB::listen(function ($event) use (&$queries) {
            $queries[] = $event->sql;
        });
        $first = $this->demoPost('attempts', $body, $demo['csrf'])->assertOk()->json('data');
        if ($method === 'cash') {
            $this->assertSame('demo_awaiting_cash', $first['attempt']['status']);
            $this->assertNull($first['attempt']['receipt']);
            $path = 'provider/'.$demo['id'].'/'.$first['attempt']['id'].'/receive';
            $this->demoPost($path, [], $demo['csrf'])->assertForbidden();
            $csrf = $this->receiver();
            $first = $this->demoPost($path, [], $csrf)->assertOk()->json('data');
            $this->demoPost($path, [], $csrf)->assertOk()->assertJsonPath('data.attempt.receipt', $first['attempt']['receipt']);
        }
        $this->assertSame('demo_completed', $first['attempt']['status']);
        $this->assertTrue($first['attempt']['receipt']['demo_only']);
        $this->assertFalse($first['attempt']['receipt']['external_charge']);
        $this->assertFalse($first['real_payments_enabled']);
        $this->assertFalse($first['reservation_changed']);
        $this->travel(20)->minutes();
        $this->demoPost('attempts', $body, $demo['csrf'])->assertOk()->assertJsonPath('data.attempt', $first['attempt']);
        $this->getJson('/demo/checkout/attempts/'.$first['attempt']['id'])->assertOk()->assertJsonPath('data.attempt', $first['attempt']);
        $this->get('/demo/checkout')->assertOk()->assertViewHas('demo', fn ($data) => $data['attempt'] === $first['attempt']);
        $this->assertSame([], $queries, 'No application database access, including sessions.');
        $state = json_decode(file_get_contents($this->demoRoot.'/contexts/'.$demo['id'].'.json'), true);
        $this->assertCount(1, $state['attempts']);
        $this->assertSame($demo['hold']['expires_at'], $first['hold']['expires_at']);
    }

    #[DataProvider('methods')]
    public function test_declined_and_temporary_results_never_issue_a_receipt_until_explicit_retry(string $method): void
    {
        [$demo] = $this->customer();
        $body = $this->payload($method, 'declined');
        $declined = $this->demoPost('attempts', $body, $demo['csrf'])->assertOk()->assertJsonPath('data.attempt.status', 'demo_declined')->assertJsonPath('data.attempt.receipt', null)->json('data');
        $this->demoPost('attempts', $body, $demo['csrf'])->assertOk()->assertJsonPath('data.attempt', $declined['attempt']);
        $fresh = $this->demoPost('new', [], $demo['csrf'])->assertOk();
        $this->withCookie('tekora_checkout_client', $fresh->getCookie('tekora_checkout_client')->getValue());
        $response = $this->get('/demo/checkout')->assertOk();
        $this->assertNotSame($demo['id'], $response->viewData('demo')['id']);
        $this->assertNull($response->viewData('demo')['attempt']);
    }

    #[DataProvider('methods')]
    public function test_temporary_retry_is_explicit_idempotent_and_keeps_one_attempt(string $method): void
    {
        [$demo] = $this->customer();
        $body = $this->payload($method, 'temporary');
        $first = $this->demoPost('attempts', $body, $demo['csrf'])->assertOk()->assertJsonPath('data.attempt.status', 'demo_temporary_error')->assertJsonPath('data.attempt.receipt', null)->json('data');
        $this->demoPost('attempts', $body, $demo['csrf'])->assertJsonPath('data.attempt', $first['attempt']);
        $path = 'attempts/'.$first['attempt']['id'].'/retry';
        $result = $this->demoPost($path, [], $demo['csrf'])->assertOk()->assertJsonPath('data.attempt.status', $method === 'cash' ? 'demo_awaiting_cash' : 'demo_completed')->json('data');
        $this->demoPost($path, [], $demo['csrf'])->assertOk()->assertJsonPath('data.attempt', $result['attempt']);
        $state = json_decode(file_get_contents($this->demoRoot.'/contexts/'.$demo['id'].'.json'), true);
        $this->assertCount(1, $state['attempts']);
        $this->assertSame($first['attempt']['id'], $result['attempt']['id']);
    }

    public function test_missing_owner_csrf_operator_and_foreign_origin_cannot_mutate_or_receive(): void
    {
        $this->getJson('/demo/checkout/context')->assertUnauthorized();
        $this->postJson('/demo/checkout/attempts', $this->payload())->assertUnauthorized();
        [$demo] = $this->customer();
        $this->postJson('/demo/checkout/attempts', $this->payload())->assertStatus(419);
        $this->postJson('/demo/checkout/attempts', $this->payload(), ['X-Checkout-Demo-CSRF' => $demo['csrf'], 'Origin' => 'https://outside.test'])->assertStatus(419);
        $this->get('/demo/checkout/provider')->assertForbidden();
        $this->get('/demo/checkout/provider/access?token=invented')->assertForbidden();
        $this->getJson('/demo/checkout/provider/pending')->assertForbidden();
        $this->getJson('/demo/checkout/attempts/'.Str::uuid())->assertNotFound();
    }

    public function test_private_provider_view_has_built_assets_and_never_exposes_customer_capabilities(): void
    {
        [$demo] = $this->customer();
        $this->receiver();
        $this->get('/demo/checkout/provider')->assertOk()->assertSee('PRESTADOR · SOLO DEMOSTRACIÓN')->assertSee('checkout-demo-receiver');
        $rows = $this->getJson('/demo/checkout/provider/pending')->assertOk()->json('data');
        $this->assertSame([], $rows);
        $this->demoPost('attempts', $this->payload('cash'), $demo['csrf'])->assertOk();
        $this->getJson('/demo/checkout/provider/pending')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonMissingPath('data.0.csrf')->assertJsonMissingPath('data.0.client_hash');
    }

    public static function tampering(): array
    {
        return [[['amount_cents' => 1]], [['total' => 1]], [['quote_id' => 'real']], [['reservation_id' => 1]],
            [['status' => 'paid']], [['captured' => true]], [['pan' => '4111111111111111']], [['cvv' => '123']],
            [['instrument' => 'external']], [['method' => 'bank']], [['actor' => 'provider_demo']]];
    }

    #[DataProvider('tampering')]
    public function test_only_controlled_tokens_are_accepted_and_amount_or_banking_data_are_rejected(array $changes): void
    {
        [$demo] = $this->customer();
        $this->demoPost('attempts', array_replace($this->payload(), $changes), $demo['csrf'])->assertStatus(422);
        $this->getJson('/demo/checkout/context')->assertJsonPath('data.attempt', null);
        $this->assertStringNotContainsString('4111111111111111', file_get_contents($this->demoRoot.'/contexts/'.$demo['id'].'.json'));
    }

    public function test_key_conflicts_and_different_intentions_cannot_duplicate_a_completed_result(): void
    {
        [$demo] = $this->customer();
        $body = $this->payload();
        $this->demoPost('attempts', $body, $demo['csrf'])->assertOk();
        $this->demoPost('attempts', array_replace($body, ['instrument' => 'credit']), $demo['csrf'])->assertConflict();
        $this->demoPost('attempts', $this->payload(), $demo['csrf'])->assertConflict();
    }

    public static function expiry(): array
    {
        return [[899999999, 200], [900000000, 409], [900000001, 409]];
    }

    #[DataProvider('expiry')]
    public function test_hold_boundary_is_exactly_900_seconds_and_never_extends(int $elapsed, int $status): void
    {
        $this->travelTo(BookingClock::date(1900000000000000));
        [$demo] = $this->customer();
        $this->travelTo(BookingClock::date(1900000000000000 + $elapsed));
        $this->demoPost('attempts', $this->payload(), $demo['csrf'])->assertStatus($status);
        $current = $this->getJson('/demo/checkout/context')->assertOk()->json('data');
        $this->assertSame($demo['hold']['expires_at'], $current['hold']['expires_at']);
        $this->assertSame(900, $current['hold']['duration_seconds']);
    }

    public function test_cash_and_temporary_recovery_cannot_bypass_expiry_or_clock_high_water(): void
    {
        $this->travelTo(BookingClock::date(1900000000000000));
        [$demo] = $this->customer();
        $cash = $this->demoPost('attempts', $this->payload('cash'), $demo['csrf'])->assertOk()->json('data');
        $csrf = $this->receiver();
        $this->travelTo(BookingClock::date(1900000900000000));
        $this->getJson('/demo/checkout/context')->assertJsonPath('data.attempt.status', 'demo_expired');
        $this->travelTo(BookingClock::date(1900000000000000));
        $this->demoPost('provider/'.$demo['id'].'/'.$cash['attempt']['id'].'/receive', [], $csrf)->assertConflict();
        $this->getJson('/demo/checkout/context')->assertJsonPath('data.hold.remaining_seconds', 0);
    }

    public function test_prices_rounding_and_accepted_b3_snapshot_are_reused_without_double_commission(): void
    {
        [$demo] = $this->customer();
        $amount = CommercialAmount::fromCents(100000);
        $this->assertSame($amount->totalCents, $demo['quote']['amount']['total_cents']);
        $this->assertSame($amount->commissionCents, $demo['quote']['amount']['commission_cents']);
        $this->assertSame($amount->providerCents, $demo['quote']['amount']['provider_cents']);
        $this->assertSame('accepted', $demo['quote']['acceptance']['state']);
        $this->assertSame($demo['quote']['amount']['total_cents'], $demo['quote']['amount']['provider_cents'] + $demo['quote']['amount']['commission_cents']);
        $this->assertCount(39, glob(database_path('migrations/*.php')));
    }

    public function test_credit_is_controlled_and_foreign_attempts_or_customer_credentials_cannot_be_adopted(): void
    {
        [$first, $cookie] = $this->customer();
        $body = array_replace($this->payload(), ['instrument' => 'credit']);
        $attempt = $this->demoPost('attempts', $body, $first['csrf'])->assertOk()->assertJsonPath('data.attempt.instrument', 'credit')->json('data.attempt');
        [$otherCookie, $other] = app(CheckoutStore::class)->create();
        $this->withCookie('tekora_checkout_client', $otherCookie);
        $this->getJson('/demo/checkout/attempts/'.$attempt['id'])->assertNotFound();
        $this->demoPost('attempts/'.$attempt['id'].'/retry', [], $other['csrf'])->assertNotFound();
        $this->withCookie('tekora_checkout_client', $first['id'].':'.bin2hex(random_bytes(32)));
        $this->getJson('/demo/checkout/context')->assertNotFound();
        $this->withCookie('tekora_checkout_client', $cookie);
        $this->getJson('/demo/checkout/context')->assertJsonPath('data.attempt', $attempt);
    }

    public function test_failed_admission_retains_high_water_and_temporary_retry_does_not_revive_a_hold(): void
    {
        $this->travelTo(BookingClock::date(1900000000000000));
        [$demo] = $this->customer();
        $first = $this->demoPost('attempts', $this->payload('card', 'temporary'), $demo['csrf'])->assertOk()->json('data');
        $this->travelTo(BookingClock::date(1900000900000000));
        $path = 'attempts/'.$first['attempt']['id'].'/retry';
        $this->demoPost($path, [], $demo['csrf'])->assertConflict();
        $this->travelTo(BookingClock::date(1900000000000000));
        $this->demoPost($path, [], $demo['csrf'])->assertConflict();
        $this->getJson('/demo/checkout/context')->assertJsonPath('data.hold.remaining_seconds', 0)->assertJsonPath('data.attempt.receipt', null);
    }

    public function test_corrupt_or_unconfigured_runtime_is_unavailable_and_never_fake_success(): void
    {
        [$demo] = $this->customer();
        file_put_contents($this->demoRoot.'/contexts/'.$demo['id'].'.json', '{broken');
        $this->getJson('/demo/checkout/context')->assertStatus(503)->assertJsonMissingPath('data')->assertDontSee('Syntax error');
        config(['checkout_demo.root' => null]);
        $this->get('/demo/checkout')->assertStatus(503)->assertJsonMissingPath('data');
    }

    public static function environments(): array
    {
        return [['production'], ['staging']];
    }

    #[DataProvider('environments')]
    public function test_all_demo_operations_are_inaccessible_outside_local_and_testing(string $env): void
    {
        $this->app->instance('env', $env);
        foreach (['', '/context', '/provider', '/provider/pending', '/provider/access?token='.$this->operatorToken] as $path) {
            $this->get('/demo/checkout'.$path)->assertNotFound();
        }
        foreach (['attempts', 'new', 'attempts/'.Str::uuid().'/retry', 'provider/'.Str::uuid().'/'.Str::uuid().'/receive'] as $path) {
            $this->postJson('/demo/checkout/'.$path, [])->assertNotFound();
        }
        $this->app->instance('env', 'testing');
    }
}
