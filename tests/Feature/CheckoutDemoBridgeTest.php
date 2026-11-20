<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Payments\ServicePricing;
use App\Payments\SyntheticQuotes;
use App\Services\ReservationWorkflow;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CheckoutDemoFixtures;
use Tests\Support\SlotHoldFixtures;
use Tests\TestCase;

class CheckoutDemoBridgeTest extends TestCase
{
    use CheckoutDemoFixtures, SlotHoldFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareDemo();
    }

    private function held(): array
    {
        [$client, $provider, $inquiry] = $this->reservationContext();
        app(ServicePricing::class)->publish($provider, $inquiry->service_id, ['amount_cents' => 230000, 'expected_version' => 0, 'idempotency_key' => (string) Str::uuid()]);
        $quote = app(SyntheticQuotes::class)->issue($client, $inquiry->id, ['idempotency_key' => (string) Str::uuid()]);
        app(SyntheticQuotes::class)->accept($client, $quote->id);
        $r = app(ReservationWorkflow::class)->propose($client, $inquiry->id, $this->reservationData(['quote_id' => $quote->id]))['reservation'];
        app(ReservationWorkflow::class)->decide($provider, $r->id, 'confirm', $this->holdInput());

        return [$client, $provider, $inquiry, $r];
    }

    private function business(): array
    {
        return $this->reservationRows(['service_inquiries', 'service_inquiry_messages', 'service_reservations', 'reservation_events', 'reservation_occupancy',
            'reservation_holds', 'reservation_hold_events', 'reservation_hold_clock', 'reservation_action_receipts', 'service_price_versions', 'service_quotes', 'quote_acceptances', 'service_reviews']);
    }

    public function test_legacy_checkout_stays_isolated_and_commercial_journey_hides_payment_without_a_b32_fixture(): void
    {
        [$client, $provider, $inquiry, $r] = $this->held();
        // This test uses SQLite :memory: without B3.2's disposable disk fixture.
        // The old independent checkout remains available directly, but the
        // commercial journey must not suggest that its example pays the hold.
        $response = $this->actingAs($client, 'web')->get(route('inquiries.show', $inquiry))
            ->assertOk()
            ->assertDontSee('Explorar el pago (DEMO)')
            ->assertDontSee('Elegir método de pago')
            ->assertSee('Confirmar definitivamente mi reserva')
            ->assertSee('$2,300.00 MXN');

        $doc = new DOMDocument;
        @$doc->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
        $xpath = new DOMXPath($doc);
        $this->assertSame(0, $xpath->query('//*[@data-checkout-demo-invitation]//a')->length);
        $this->assertSame(1, $xpath->query('//form[@action="'.route('reservations.commit', $r).'"]')->length);
        $this->assertStringNotContainsString(route('checkout.local.show', $r), $response->getContent());

        $this->actingAs($provider, 'web')->get(route('inquiries.show', $inquiry))
            ->assertOk()->assertDontSee('Elegir método de pago')->assertDontSee('/demo/checkout/provider');
        $this->get('/demo/checkout/provider')->assertForbidden();
        $this->actingAs($this->account(RoleName::Client), 'web')
            ->get(route('inquiries.show', $inquiry))->assertForbidden()->assertDontSee('Elegir método de pago');

        // B3.1-D still works independently, without reservation IDs or customer context.
        $this->get(route('demo.checkout'))->assertOk()
            ->assertSee('Simulación independiente; el resultado NO paga ni confirma esta reserva.');
    }

    #[DataProvider('methods')]
    public function test_demo_results_do_not_change_any_commercial_row_or_confirm_b2(string $method): void
    {
        [$client, $provider, $inquiry, $r] = $this->held();
        $this->actingAs($client, 'web');
        $before = $this->business();
        $queries = [];
        DB::listen(function ($q) use (&$queries) {
            $queries[] = $q->sql;
        });
        [$demo] = $this->customer();
        $body = ['method' => $method, 'scenario' => 'success', 'idempotency_key' => (string) Str::uuid()] + ($method === 'card' ? ['instrument' => 'credit'] : []);
        $result = $this->demoPost('attempts', $body, $demo['csrf'])->assertOk()->json('data');
        if ($method === 'cash') {
            $this->assertSame('demo_awaiting_cash', $result['attempt']['status']);
            $this->assertNull($result['attempt']['receipt']);
            $path = 'provider/'.$demo['id'].'/'.$result['attempt']['id'].'/receive';
            $this->demoPost($path, [], $demo['csrf'])->assertForbidden();
            $operatorCsrf = $this->receiver();
            $result = $this->demoPost($path, [], $operatorCsrf)->assertOk()->json('data');
            $this->assertSame('provider_demo', $result['attempt']['receipt']['recorded_by']);
        }
        $this->assertSame([], $queries, 'Demo operations must not query the business database, including sessions.');
        $this->assertSame('demo_completed', $result['attempt']['status']);
        $this->assertTrue($result['attempt']['receipt']['demo_only']);
        $this->assertFalse($result['attempt']['receipt']['external_charge']);
        $this->assertSame(100000, $result['quote']['amount']['total_cents']);
        $this->assertSame($before, $this->business());
        $this->assertSame('held', $r->fresh()->effectiveStatus()->value);
        $api = $this->getJson('/api/v1/inquiries/'.$inquiry->id.'/journey', $this->bearer($client))->assertOk()->assertJsonPath('data.reservation.status', 'held')->assertJsonPath('data.commercial.agreed_amount.total_cents', 230000);
        foreach (['/demo/', 'tekora_checkout', 'client_hash', 'operator_hash', 'capability', $demo['csrf']] as $secret) {
            $this->assertStringNotContainsString($secret, $api->getContent());
        }
        $this->actingAs($client, 'web')->patch(route('reservations.commit', $r), $this->holdInput(['_journey' => 1]))->assertRedirect(route('inquiries.show', $inquiry).'#reservas');
        $this->assertSame('confirmed', $r->fresh()->effectiveStatus()->value);
        $this->get(route('inquiries.show', $inquiry))->assertOk()->assertDontSee('Explorar el pago (DEMO)')->assertSee('$2,300.00 MXN');
        $this->assertHoldIntegrity();
    }

    public static function methods(): array
    {
        return [['card'], ['cash']];
    }

    public function test_b2_confirmation_does_not_require_a_demo_result_or_demo_cookie(): void
    {
        [$client, $provider, $inquiry, $r] = $this->held();
        $this->actingAs($client, 'web')->patch(route('reservations.commit', $r), $this->holdInput())->assertStatus(303);
        $this->assertSame('confirmed', $r->fresh()->effectiveStatus()->value);
        $this->assertSame([], glob($this->demoRoot.'/contexts/*.json'));
    }

    public function test_successful_demo_receipt_cannot_bypass_real_hold_expiry_or_renew_it(): void
    {
        [$client, $provider, $inquiry, $r] = $this->held();
        $expires = $r->hold->expires_at_us;
        [$demo] = $this->customer();
        $this->demoPost('attempts', ['method' => 'card', 'instrument' => 'debit', 'scenario' => 'success', 'idempotency_key' => (string) Str::uuid()], $demo['csrf'])->assertOk()->assertJsonPath('data.attempt.status', 'demo_completed');
        $this->travel(900)->seconds();
        $this->actingAs($client, 'web')->patch(route('reservations.commit', $r), $this->holdInput())->assertConflict();
        $this->assertSame('hold_expired', $r->fresh()->effectiveStatus()->value);
        $this->assertSame($expires, $r->fresh()->hold->expires_at_us);
        $this->get(route('inquiries.show', $inquiry))->assertOk()->assertDontSee('Explorar el pago (DEMO)');
        $this->assertHoldIntegrity();
    }

    #[DataProvider('environments')]
    public function test_unapproved_environments_never_render_the_link_and_reject_demo_routes(string $env): void
    {
        [$client, $provider, $inquiry] = $this->held();
        $this->app->instance('env', $env);
        try {
            $this->actingAs($client, 'web')->get(route('inquiries.show', $inquiry))->assertOk()->assertDontSee('Explorar el pago (DEMO)');
            $this->get('/demo/checkout')->assertNotFound();
        } finally {
            $this->app->instance('env', 'testing');
        }
    }

    public static function environments(): array
    {
        return [['production'], ['staging']];
    }

    #[DataProvider('remoteBoundaries')]
    public function test_local_demo_boundary_rejects_remote_ips_or_nonloopback_hosts(string $ip, string $host): void
    {
        [$client, $provider, $inquiry] = $this->held();
        $this->withServerVariables(['REMOTE_ADDR' => $ip]);
        $url = 'http://'.$host;
        $this->actingAs($client, 'web')->get($url.'/cuenta/solicitudes/'.$inquiry->id)->assertOk()->assertDontSee('Explorar el pago (DEMO)');
        $this->get($url.'/demo/checkout')->assertNotFound();
    }

    public static function remoteBoundaries(): array
    {
        return [['203.0.113.20', 'localhost'], ['127.0.0.1', 'outside.example']];
    }

    public function test_cached_configuration_hides_invitation_and_blocks_demo(): void
    {
        [$client, $provider, $inquiry] = $this->held();
        $cache = $this->app->getCachedConfigPath();
        $this->assertFileDoesNotExist($cache);
        file_put_contents($cache, '<?php return [];');
        try {
            $this->actingAs($client, 'web')->get(route('inquiries.show', $inquiry))->assertOk()->assertDontSee('Explorar el pago (DEMO)');
            $this->get('/demo/checkout')->assertNotFound();
        } finally {
            unlink($cache);
        }
    }

    public function test_guest_never_receives_a_journey_link_and_business_context_is_rejected_at_destination(): void
    {
        [$client, $provider, $inquiry, $r] = $this->held();
        $this->get(route('inquiries.show', $inquiry))->assertRedirect(route('login'))->assertDontSee('Explorar el pago (DEMO)');
        $this->get('/demo/checkout?reservation_id='.$r->id.'&return_url=https://outside.example')->assertUnprocessable()->assertDontSee('outside.example');
        [$demo] = $this->customer();
        $this->get('/demo/checkout')->assertOk()->assertSee('Simulación independiente; el resultado NO paga ni confirma esta reserva. Los importes mostrados son ficticios.')->assertSee(route('inquiries.index'))->assertDontSee('return_url=');
    }
}
