<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Http\Controllers\LocalIntegratedCheckoutController;
use App\Payments\CheckoutContext;
use App\Payments\LocalCheckoutAttemptStore;
use App\Services\ReservationWorkflow;
use App\Support\BookingClock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\SlotHoldFixtures;
use Tests\TestCase;

class MobileConfirmationReadinessTest extends TestCase
{
    use SlotHoldFixtures;

    private ?string $fixtureDir = null;

    protected function tearDown(): void
    {
        if ($this->fixtureDir === null) {
            parent::tearDown();

            return;
        }
        $pdo = \WeakReference::create(DB::connection()->getPdo());
        Auth::forgetGuards();
        DB::purge('sqlite');
        $this->app->forgetScopedInstances();
        $this->assertNull($pdo->get(), 'Release SQLite before removing the disposable fixture.');
        parent::tearDown();
        foreach (['tekora.sqlite', 'tekora.sqlite-journal', 'tekora.sqlite-wal', 'tekora.sqlite-shm', '.tekora-b32-synthetic'] as $name) {
            $file = $this->fixtureDir.DIRECTORY_SEPARATOR.$name;
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($this->fixtureDir);
    }

    private function held(): array
    {
        $data = $this->pricedProposal();
        app(ReservationWorkflow::class)->decide($data[1], $data[4]->id, 'confirm', $this->holdInput());

        return $data;
    }

    private function useLocalFixture(): void
    {
        DB::statement("CREATE TABLE synthetic_checkout_attempts (
            reservation_id INTEGER PRIMARY KEY, client_user_id INTEGER NOT NULL,
            provider_user_id INTEGER NOT NULL, quote_id TEXT NOT NULL,
            amount_cents INTEGER NOT NULL CHECK(amount_cents > 0),
            method TEXT NOT NULL CHECK(method IN ('card','cash')), scenario TEXT NOT NULL,
            status TEXT NOT NULL CHECK(status IN ('test_approved','declined','temporary_failure','awaiting_cash','cash_received')),
            idempotency_key TEXT NOT NULL, reference TEXT NOT NULL UNIQUE,
            attempted_at TEXT NOT NULL, received_at TEXT NULL
        )");
        $dir = realpath(sys_get_temp_dir()).DIRECTORY_SEPARATOR.'tekora-b32-'.bin2hex(random_bytes(12));
        $this->assertTrue(mkdir($dir, 0700));
        $this->fixtureDir = $dir;
        $pdo = DB::connection()->getPdo();
        $pdo->exec('VACUUM main INTO '.$pdo->quote($dir.DIRECTORY_SEPARATOR.'tekora.sqlite'));
        $pdo = null;
        file_put_contents($dir.DIRECTORY_SEPARATOR.'.tekora-b32-synthetic', "M2.1 TEST ONLY\n");
        DB::purge('sqlite');
        $this->app->forgetScopedInstances();
        config(['database.connections.sqlite.database' => $dir.DIRECTORY_SEPARATOR.'tekora.sqlite']);
        $this->withServerVariables(['HTTP_HOST' => 'localhost', 'REMOTE_ADDR' => '127.0.0.1']);
        $this->assertTrue(LocalIntegratedCheckoutController::permitsSyntheticRequest(Request::create('http://localhost/', server: ['REMOTE_ADDR' => '127.0.0.1'])));
    }

    private function snapshot(): array
    {
        $tables = DB::select("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' AND name NOT IN ('personal_access_tokens') ORDER BY name");

        return $this->reservationRows(array_column($tables, 'name'));
    }

    private function readings($client, $inquiry, $reservation, string $state, bool $canOffer): array
    {
        $before = $this->snapshot();
        $context = $this->getJson('/api/v1/reservations/'.$reservation->id.'/checkout-context', $this->bearer($client))
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.future_payment.enabled', false)->assertJsonPath('data.future_payment.can_start', false)
            ->assertJsonPath('data.confirmation_readiness.state', $state)
            ->assertJsonPath('data.confirmation_readiness.can_offer_mobile_confirmation', $canOffer)->json('data');
        $journey = $this->getJson('/api/v1/inquiries/'.$inquiry->id.'/journey', $this->bearer($client))
            ->assertOk()->assertJsonPath('data.payments.enabled', false)->json('data');
        $this->assertSame($context['confirmation_readiness'], $journey['confirmation_readiness']);
        $this->assertSame($context['server_now'], $context['confirmation_readiness']['observed_at']);
        $this->assertSame($journey['server_now'], $journey['confirmation_readiness']['observed_at']);
        $this->assertSame($reservation->fresh()->version, $context['confirmation_readiness']['reservation_version']);
        $this->assertReadinessContract($context['confirmation_readiness']);
        $this->assertSame($before, $this->snapshot());
        $this->assertHoldIntegrity();

        return $context;
    }

    private function assertReadinessContract(array $value): void
    {
        $spec = json_decode(file_get_contents(base_path('docs/api/openapi.json')), true, flags: JSON_THROW_ON_ERROR);
        $schema = $spec['components']['schemas']['ConfirmationReadiness'];
        $this->assertFalse($schema['additionalProperties']);
        $this->assertSame($schema['required'], array_keys($value));
        $this->assertContains($value['state'], $schema['properties']['state']['enum']);
        $this->assertContains($value['synthetic_web_requirement'], $schema['properties']['synthetic_web_requirement']['enum']);
        $this->assertSame(true, $schema['properties']['advisory_only']['const']);
        $this->assertTrue($value['advisory_only']);
        $this->assertSame($value['state'] === 'ready', $value['can_offer_mobile_confirmation']);
        foreach (['reservation_id', 'reservation_version'] as $field) {
            $this->assertIsInt($value[$field]);
            $this->assertGreaterThanOrEqual($schema['properties'][$field]['minimum'], $value[$field]);
        }
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{6}Z$/D', $value['observed_at']);
        $this->assertSame(array_values(array_unique($value['blocking_reasons'])), $value['blocking_reasons']);
        foreach ($value['blocking_reasons'] as $reason) {
            $this->assertContains($reason, $schema['properties']['blocking_reasons']['items']['enum']);
        }
    }

    public function test_waiting_provider_and_normal_b2_ready_replay_and_stale_version(): void
    {
        [$client, $provider, $inquiry, , $r] = $this->pricedProposal();
        $this->readings($client, $inquiry, $r, 'awaiting_provider', false);
        app(ReservationWorkflow::class)->decide($provider, $r->id, 'confirm', $this->holdInput());
        $context = $this->readings($client, $inquiry, $r, 'ready', true);
        $this->assertSame('not_applicable', $context['confirmation_readiness']['synthetic_web_requirement']);
        $before = $this->snapshot();
        $this->patchJson('/api/v1/reservations/'.$r->id.'/commit', $this->holdInput(['version' => 99]), $this->bearer($client))->assertConflict();
        $this->assertSame($before, $this->snapshot());
        $input = $this->holdInput(['version' => $context['reservation']['version']]);
        $this->patchJson('/api/v1/reservations/'.$r->id.'/commit', $input, $this->bearer($client))->assertOk();
        $this->readings($client, $inquiry, $r, 'already_confirmed', false);
        $confirmed = $this->snapshot();
        $this->patchJson('/api/v1/reservations/'.$r->id.'/commit', $input, $this->bearer($client))->assertOk();
        $this->assertSame($confirmed, $this->snapshot());
    }

    public static function choices(): array
    {
        return ['missing' => [null, null, false], 'declined' => ['card', 'declined', false],
            'temporary' => ['card', 'temporary_failure', false], 'approved' => ['card', 'test_approved', true],
            'cash_pending' => ['cash', 'awaiting_cash', true], 'cash_received' => ['cash', 'cash_received', true]];
    }

    #[DataProvider('choices')]
    public function test_private_fixture_uses_the_actual_commit_predicate_without_exposing_instruments(?string $method, ?string $status, bool $canOffer): void
    {
        [$client, $provider, $inquiry, , $r] = $this->held();
        $this->useLocalFixture();
        if ($method !== null) {
            app(LocalCheckoutAttemptStore::class)->submit($client, app(CheckoutContext::class)->forReservation($client, $r->id), $method, $status, (string) Str::uuid());
            if ($status === 'cash_received') {
                app(LocalCheckoutAttemptStore::class)->receiveCash($provider, $r->fresh());
            }
        }
        $ctx = $this->readings($client, $inquiry, $r, $canOffer ? 'ready' : 'web_checkout_required', $canOffer);
        $this->assertSame($canOffer ? 'satisfied' : 'required', $ctx['confirmation_readiness']['synthetic_web_requirement']);
        $journey = $this->getJson('/api/v1/inquiries/'.$inquiry->id.'/journey', $this->bearer($client))->assertOk();
        // The old navigation hint is deliberately preserved, not silently redefined.
        $journey->assertJsonPath('data.next_action.code', 'confirm_reservation')->assertJsonPath('data.next_action.mobile_execution_available', true);
        $encoded = json_encode($ctx['confirmation_readiness'], JSON_THROW_ON_ERROR);
        foreach (['method', 'card_number', 'cvv', 'reference', 'test_approved', 'cash_received', 'idempotency_key'] as $secret) {
            $this->assertStringNotContainsString($secret, $encoded);
        }
        $before = $this->snapshot();
        $response = $this->patchJson('/api/v1/reservations/'.$r->id.'/commit', $this->holdInput(['version' => $r->fresh()->version]), $this->bearer($client));
        if ($canOffer) {
            $response->assertOk()->assertJsonPath('data.status', 'confirmed');
        } else {
            $response->assertConflict();
            $this->assertSame($before, $this->snapshot());
        }
    }

    public static function boundaries(): array
    {
        return [[899999999, 'ready', true, 1], [900000000, 'hold_expired', false, 0], [900000001, 'hold_expired', false, 0]];
    }

    #[DataProvider('boundaries')]
    public function test_exact_expiration_is_read_only_and_never_extends_the_hold(int $offset, string $state, bool $canOffer, int $remaining): void
    {
        [$client, , $inquiry, , $r] = $this->held();
        $hold = $r->fresh()->hold;
        $this->travelTo(BookingClock::date($hold->accepted_at_us + $offset));
        $ctx = $this->readings($client, $inquiry, $r, $state, $canOffer);
        $this->assertSame($remaining, $ctx['hold']['remaining_seconds']);
        $this->assertSame(900, $ctx['hold']['hold_seconds']);
        $this->assertSame($hold->expires_at_us, $r->fresh()->hold->expires_at_us);
    }

    public static function closedActions(): array
    {
        return [['cancel', false, 'hold_released'], ['reject', false, 'rejected'],
            ['cancel', true, 'cancelled'], ['complete', true, 'completed']];
    }

    #[DataProvider('closedActions')]
    public function test_closed_and_released_reservations_never_offer_confirmation(string $action, bool $commit, string $expected): void
    {
        if ($action === 'reject') {
            [$client, $provider, $inquiry, , $r] = $this->pricedProposal();
        } else {
            [$client, $provider, $inquiry, , $r] = $this->held();
        }
        if ($commit) {
            app(ReservationWorkflow::class)->decide($client, $r->id, 'commit', $this->holdInput());
        }
        if ($action === 'complete') {
            $this->travelTo(\Carbon\CarbonImmutable::parse($r->ends_at_utc, 'UTC'));
        }
        $actor = in_array($action, ['reject', 'complete'], true) ? $provider : $client;
        app(ReservationWorkflow::class)->decide($actor, $r->id, $action, $this->holdInput(['version' => $r->fresh()->version]));
        $this->readings($client, $inquiry, $r, $expected, false);
    }

    public function test_ownership_authentication_and_dual_roles_remain_enforced(): void
    {
        [$client, $provider, $inquiry, , $r] = $this->held();
        foreach (['/api/v1/reservations/'.$r->id.'/checkout-context', '/api/v1/inquiries/'.$inquiry->id.'/journey'] as $url) {
            Auth::forgetGuards();
            $this->getJson($url)->assertUnauthorized();
            $this->getJson($url, ['Authorization' => 'Bearer invalid'])->assertUnauthorized();
            foreach ([$provider, $this->account(RoleName::Client, RoleName::Provider)] as $other) {
                $this->getJson($url, $this->bearer($other))->assertNotFound();
            }
            $this->getJson($url.'?can_offer_mobile_confirmation=true', $this->bearer($client))->assertUnprocessable();
        }
        $client->roles()->attach(\App\Models\Role::where('slug', RoleName::Provider->value)->value('id'));
        $this->readings($client, $inquiry, $r, 'ready', true);
    }

    public function test_rolled_back_clock_reports_unknown_instead_of_offering_commit(): void
    {
        [$client, , $inquiry, , $r] = $this->held();
        $this->travelTo(BookingClock::date($r->fresh()->hold->accepted_at_us - 1));
        $ctx = $this->readings($client, $inquiry, $r, 'unknown', false);
        $this->assertContains('server_clock_uncertain', $ctx['confirmation_readiness']['blocking_reasons']);
        $this->patchJson('/api/v1/reservations/'.$r->id.'/commit', $this->holdInput(), $this->bearer($client))->assertStatus(503);
    }

    public function test_missing_synthetic_storage_is_503_not_a_satisfied_requirement(): void
    {
        [$client, , $inquiry, , $r] = $this->held();
        $this->useLocalFixture();
        DB::statement('DROP TABLE synthetic_checkout_attempts');
        foreach (['/api/v1/reservations/'.$r->id.'/checkout-context', '/api/v1/inquiries/'.$inquiry->id.'/journey'] as $url) {
            $this->getJson($url, $this->bearer($client))->assertStatus(503)->assertJsonPath('error.code', 'temporarily_unavailable')->assertJsonMissingPath('data');
        }
    }

    public function test_revoked_provider_permission_is_a_blocker_not_a_payment_requirement(): void
    {
        [$client, $provider, $inquiry, , $r] = $this->held();
        $provider->roles()->detach();
        $ctx = $this->readings($client, $inquiry, $r, 'blocked', false);
        $this->assertContains('participants_unavailable', $ctx['confirmation_readiness']['blocking_reasons']);
    }

    public function test_the_private_rule_is_not_activated_in_production_or_by_a_table_alone(): void
    {
        [$client, , $inquiry, , $r] = $this->held();
        $this->useLocalFixture();
        // No synthetic query may run when the environment is outside B3.2.
        DB::statement('DROP TABLE synthetic_checkout_attempts');
        $this->app->instance('env', 'production');
        $ctx = $this->readings($client, $inquiry, $r, 'ready', true);
        $this->assertSame('not_applicable', $ctx['confirmation_readiness']['synthetic_web_requirement']);
    }

    public function test_forged_binding_never_satisfies_the_synthetic_requirement(): void
    {
        [$client, , $inquiry, , $r] = $this->held();
        $this->useLocalFixture();
        app(LocalCheckoutAttemptStore::class)->submit($client, app(CheckoutContext::class)->forReservation($client, $r->id), 'card', 'test_approved', (string) Str::uuid());
        DB::table('synthetic_checkout_attempts')->where('reservation_id', $r->id)->update(['amount_cents' => 1]);
        $this->readings($client, $inquiry, $r, 'web_checkout_required', false);
        $this->patchJson('/api/v1/reservations/'.$r->id.'/commit', $this->holdInput(), $this->bearer($client))->assertConflict();
    }

    public function test_a_missing_clock_never_returns_a_ready_payload(): void
    {
        [$client, , $inquiry, , $r] = $this->held();
        // Deliberate corruption only in this new in-memory test database.
        DB::unprepared('DROP TRIGGER hold_clock_no_delete');
        DB::table('reservation_hold_clock')->where('id', 1)->delete();
        foreach (['/api/v1/reservations/'.$r->id.'/checkout-context', '/api/v1/inquiries/'.$inquiry->id.'/journey'] as $url) {
            $this->getJson($url, $this->bearer($client))->assertStatus(503)->assertJsonMissingPath('data');
        }
    }

    public function test_legacy_and_no_reservation_have_no_new_commit_capability(): void
    {
        [$client, , $inquiry] = $this->reservationContext();
        $this->getJson('/api/v1/inquiries/'.$inquiry->id.'/journey', $this->bearer($client))->assertOk()->assertJsonPath('data.confirmation_readiness', null);
        $r = $this->proposeReservation($inquiry);
        $this->readings($client, $inquiry, $r, 'not_applicable', false);
    }

    public function test_inconsistent_quote_snapshot_is_503_for_both_read_models(): void
    {
        [$client, , $inquiry, $quote, $r] = $this->held();
        $wrong = new \App\Payments\SyntheticQuote($quote->id, $quote->inquiryId, $quote->serviceId, $quote->providerProfileId, $client->id + 100,
            $quote->price, $quote->idempotencyKey, $quote->issuedAt, $quote->expiresAt);
        $this->mock(\App\Payments\PricingStore::class)->shouldReceive('quote')->with($quote->id)->andReturn($wrong);
        // Journey consults the published price before resolving its quote.
        app(\App\Payments\PricingStore::class)->shouldReceive('latestPrice')->andReturn($quote->price);
        foreach (['/api/v1/reservations/'.$r->id.'/checkout-context', '/api/v1/inquiries/'.$inquiry->id.'/journey'] as $url) {
            $this->getJson($url, $this->bearer($client))->assertStatus(503)->assertJsonMissingPath('data')->assertJsonPath('error.code', 'temporarily_unavailable');
        }
    }
}
