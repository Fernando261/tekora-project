<?php

namespace Tests\Feature;

use App\Enums\InquiryStatus;
use App\Enums\RoleName;
use App\Models\Service;
use App\Payments\PricingStore;
use App\Payments\ServicePricing;
use App\Payments\SyntheticQuotes;
use App\Payments\WebMoney;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ReservationFixtures;
use Tests\TestCase;

class WebPricingTest extends TestCase
{
    use ReservationFixtures;

    public function test_all_37_historical_migration_sources_remain_byte_identical(): void
    {
        $expected = json_decode(file_get_contents(base_path('tests/Fixtures/pricing-historical-migrations.json')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertCount(37, $expected);
        foreach ($expected as $file => $hash) {
            $this->assertSame($hash, hash_file('sha256', base_path($file)), $file);
        }
        $this->assertCount(38, glob(database_path('migrations/*.php')));
    }

    private function price($provider, int $serviceId, array $overrides = [])
    {
        return app(ServicePricing::class)->publish($provider, $serviceId, $overrides + ['amount_cents' => 100000, 'expected_version' => 0, 'idempotency_key' => (string) Str::uuid()]);
    }

    private function quote($client, $inquiry)
    {
        return app(SyntheticQuotes::class)->issue($client, $inquiry->id, ['idempotency_key' => (string) Str::uuid()]);
    }

    private function integrity(): void
    {
        $this->assertSame(1, (int) DB::selectOne('PRAGMA foreign_keys')->foreign_keys);
        $this->assertSame('ok', DB::selectOne('PRAGMA integrity_check')->integrity_check);
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
    }

    public function test_full_web_flow_keeps_immutable_quote_after_price_change_and_creates_no_reservation(): void
    {
        [$client, $provider, $inquiry] = $this->reservationContext();
        $this->actingAs($provider)->post(route('provider-services.price', $inquiry->service_id), ['price_mxn' => '1000.00', 'expected_version' => '0', 'idempotency_key' => (string) Str::uuid()])->assertRedirect();
        $this->get(route('provider-services.index'))->assertOk()->assertSee('$1,000.00 MXN');
        $this->get(route('provider-services.edit', $inquiry->service_id))->assertOk()->assertSee('Precio del servicio');
        $this->get(route('catalog.index'))->assertOk()->assertSee('$1,000.00 MXN');
        $this->get(route('catalog.show', $inquiry->service_id))->assertOk()->assertSee('$1,000.00 MXN');
        $key = (string) Str::uuid();
        $response = $this->actingAs($client)->post(route('quotes.issue', $inquiry), ['idempotency_key' => $key]);
        $response->assertRedirect();
        $quote = app(PricingStore::class)->quoteByKey($client->id, $key);
        $this->get(route('quotes.show', $quote->id))->assertOk()->assertSee('$150.00 MXN')->assertSee('$850.00 MXN')->assertSee('Cotización pendiente');
        $this->post(route('quotes.accept', $quote->id), ['acknowledge' => '1'])->assertRedirect();
        $snapshot = (array) DB::table('service_quotes')->first();
        $this->price($provider, $inquiry->service_id, ['amount_cents' => 200000, 'expected_version' => 1]);
        $this->get(route('quotes.show', $quote->id))->assertOk()->assertSee('$1,000.00 MXN')->assertDontSee('$2,000.00 MXN')->assertSee('Cotización aceptada');
        $this->actingAs($provider)->get(route('quotes.show', $quote->id))->assertOk()->assertSee('$850.00 MXN');
        $this->assertSame($snapshot, (array) DB::table('service_quotes')->first());
        $this->assertDatabaseCount('service_reservations', 0);
        $this->assertSame(InquiryStatus::Accepted, $inquiry->fresh()->status);
        $this->integrity();
    }

    public function test_old_services_have_no_price_and_keep_their_inquiry_flow(): void
    {
        [$client, , $inquiry] = $this->reservationContext();
        $this->get(route('catalog.index'))->assertOk()->assertSee('Precio no publicado')->assertDontSee('$0.00');
        $this->actingAs($client)->get(route('inquiries.show', $inquiry))->assertOk()->assertSee('Precio no publicado')->assertSee('Reservas de esta solicitud')->assertDontSee('Consultar nueva cotización');
        $this->post(route('quotes.issue', $inquiry), ['idempotency_key' => (string) Str::uuid()])->assertStatus(409);
        $this->integrity();
    }

    public function test_pausing_publication_hides_acceptance_without_altering_quote_history(): void
    {
        [$client, $provider, $inquiry] = $this->reservationContext();
        $this->price($provider, $inquiry->service_id);
        $quote = $this->quote($client, $inquiry);
        $before = (array) DB::table('service_quotes')->first();
        $inquiry->service->update(['status' => 'paused']);
        $this->actingAs($client)->get(route('quotes.show', $quote->id))->assertOk()->assertDontSee('Aceptar cotización')->assertSee('El historial se conserva.');
        $this->postJson(route('quotes.accept', $quote->id), ['acknowledge' => 1])->assertNotFound();
        $this->assertDatabaseCount('quote_acceptances', 0);
        $this->assertSame($before, (array) DB::table('service_quotes')->first());
        $inquiry->service->update(['status' => 'published']);
        $this->get(route('quotes.show', $quote->id))->assertOk()->assertSee('Aceptar cotización');
        $this->post(route('quotes.accept', $quote->id), ['acknowledge' => 1])->assertRedirect();
        $this->assertDatabaseCount('quote_acceptances', 1);
        $this->integrity();
    }

    public static function invalidMoney(): array
    {
        return array_map(fn ($value) => [$value], ['0', '0.00', '-1', '+1', '01.00', '1,000.00', '1.001', '1e3', ' 1.00', '1.00 ', "1.00\n", '$1.00', '10000000.01', '100000000000000', '١', '1,50', [], 1.5, true, null]);
    }

    #[DataProvider('invalidMoney')]
    public function test_web_rejects_manipulated_money_without_new_versions(mixed $value): void
    {
        [, $provider, $inquiry] = $this->reservationContext();
        $this->actingAs($provider)->postJson(route('provider-services.price', $inquiry->service_id), ['price_mxn' => $value, 'expected_version' => 0, 'idempotency_key' => (string) Str::uuid()])->assertUnprocessable();
        $this->assertDatabaseCount('service_price_versions', 0);
    }

    public function test_money_conversion_and_display_are_exact_for_centavos_and_ceiling(): void
    {
        foreach (['0.01' => 1, '0.1' => 10, '10.50' => 1050, '1000.00' => 100000, '10000000.00' => 1000000000] as $decimal => $cents) {
            $this->assertSame($cents, WebMoney::cents($decimal));
            $this->assertSame($cents, WebMoney::cents(WebMoney::decimal($cents)));
        }
        $this->assertSame('$10,000,000.00 MXN', WebMoney::format(1000000000));
    }

    public function test_owner_only_price_writes_and_private_participant_only_quotes(): void
    {
        [$client, $provider, $inquiry] = $this->reservationContext();
        $other = $this->account(RoleName::Client, RoleName::Provider);
        $input = ['price_mxn' => '1000', 'expected_version' => 0, 'idempotency_key' => (string) Str::uuid()];
        foreach ([$client, $other] as $actor) {
            $this->actingAs($actor)->postJson(route('provider-services.price', $inquiry->service_id), $input)->assertForbidden();
        }
        $this->price($provider, $inquiry->service_id);
        $quote = $this->quote($client, $inquiry);
        $this->actingAs($other)->get(route('quotes.show', $quote->id))->assertForbidden();
        $this->postJson(route('quotes.accept', $quote->id), ['acknowledge' => 1])->assertForbidden();
        $this->actingAs($provider)->postJson(route('quotes.accept', $quote->id), ['acknowledge' => 1])->assertForbidden();
        $this->post(route('quotes.issue', $inquiry), ['idempotency_key' => (string) Str::uuid()])->assertNotFound();
        $this->assertDatabaseCount('quote_acceptances', 0);
    }

    public function test_creation_with_price_is_atomic_and_retry_safe_without_duplicating_service(): void
    {
        $provider = $this->account(RoleName::Provider);
        $this->service($provider); // profile exists, and legacy service is untouched
        $payload = $this->serviceData() + ['price_mxn' => '99.99', 'creation_key' => (string) Str::uuid()];
        $before = Service::count();
        $first = $this->actingAs($provider)->post(route('provider-services.store'), $payload);
        $first->assertRedirect();
        $this->post(route('provider-services.store'), $payload)->assertRedirect($first->headers->get('Location'));
        $this->assertSame($before + 1, Service::count());
        $this->assertDatabaseHas('service_price_versions', ['total_cents' => 9999, 'commission_cents' => 1500, 'provider_cents' => 8499]);
        $this->postJson(route('provider-services.store'), array_replace($payload, ['price_mxn' => '100']))->assertConflict();
        $this->assertDatabaseCount('service_pricing_creations', 1);
        $this->integrity();
    }

    public function test_creation_rolls_back_service_when_price_storage_fails(): void
    {
        $provider = $this->account(RoleName::Provider);
        $this->service($provider);
        DB::unprepared("CREATE TRIGGER test_reject_price BEFORE INSERT ON service_price_versions BEGIN SELECT RAISE(ABORT,'injected'); END");
        $before = Service::count();
        $this->actingAs($provider)->post(route('provider-services.store'), $this->serviceData() + ['price_mxn' => '100', 'creation_key' => (string) Str::uuid()])->assertStatus(503);
        $this->assertSame($before, Service::count());
        $this->assertDatabaseCount('service_price_versions', 0);
        $this->assertDatabaseCount('service_pricing_creations', 0);
        $this->integrity();
    }

    public function test_idempotency_conflicts_versions_and_quote_expiry_boundary(): void
    {
        [$client, $provider, $inquiry] = $this->reservationContext();
        $key = (string) Str::uuid();
        $first = $this->price($provider, $inquiry->service_id, ['idempotency_key' => $key]);
        $this->assertEquals($first, $this->price($provider, $inquiry->service_id, ['idempotency_key' => $key]));
        $this->actingAs($provider)->postJson(route('provider-services.price', $inquiry->service_id), ['price_mxn' => '5', 'expected_version' => 0, 'idempotency_key' => (string) Str::uuid()])->assertConflict();
        config(['quotes.lifetime_seconds' => 61]);
        $quote = $this->quote($client, $inquiry);
        $this->assertSame(61, $quote->expiresAt->getTimestamp() - $quote->issuedAt->getTimestamp());
        $this->travelTo($quote->expiresAt);
        $this->actingAs($client)->postJson(route('quotes.accept', $quote->id), ['acknowledge' => 1])->assertConflict();
        $this->get(route('quotes.show', $quote->id))->assertSee('Cotización caducada');
        $this->assertDatabaseCount('quote_acceptances', 0);
        $this->integrity();
    }

    public function test_acceptance_requires_explicit_consent_is_idempotent_and_keeps_history_after_expiry(): void
    {
        [$client, $provider, $inquiry] = $this->reservationContext();
        $this->price($provider, $inquiry->service_id);
        $quote = $this->quote($client, $inquiry);
        $this->actingAs($client)->postJson(route('quotes.accept', $quote->id), [])->assertUnprocessable();
        $this->postJson(route('quotes.accept', $quote->id), ['acknowledge' => 1, 'total_cents' => 1])->assertUnprocessable();
        $this->post(route('quotes.accept', $quote->id), ['acknowledge' => 1])->assertRedirect();
        $before = (array) DB::table('quote_acceptances')->first();
        $this->travelTo($quote->expiresAt->modify('+1 day'));
        $this->post(route('quotes.accept', $quote->id), ['acknowledge' => 1])->assertRedirect();
        $this->get(route('quotes.show', $quote->id))->assertSee('Cotización aceptada')->assertDontSee('Cotización caducada');
        $this->assertSame($before, (array) DB::table('quote_acceptances')->first());
        $this->integrity();
    }

    public static function protectedMutation(): array
    {
        return [['service_price_versions', 'total_cents', 1], ['service_quotes', 'total_cents', 1], ['quote_acceptances', 'client_user_id', 1], ['services', 'provider_profile_id', 999], ['provider_profiles', 'user_id', 999], ['service_inquiries', 'client_user_id', 999]];
    }

    #[DataProvider('protectedMutation')]
    public function test_sqlite_rejects_rewriting_snapshots_or_participant_identity(string $table, string $field, int $value): void
    {
        [$client, $provider, $inquiry] = $this->reservationContext();
        $this->price($provider, $inquiry->service_id);
        $quote = $this->quote($client, $inquiry);
        app(SyntheticQuotes::class)->accept($client, $quote->id);
        $before = DB::table($table)->get()->toJson();
        try {
            DB::table($table)->update([$field => $value]);
            $this->fail('La modificación debió rechazarse.');
        } catch (\Illuminate\Database\QueryException $error) {
            $this->assertSame(19, $error->errorInfo[1] & 255);
        }
        $this->assertSame($before, DB::table($table)->get()->toJson());
        $this->integrity();
    }

    public function test_manual_and_pdo_external_transactions_are_never_adopted_or_rolled_back(): void
    {
        $store = app(PricingStore::class);
        $pdo = DB::connection()->getPdo();
        foreach (['pdo', 'manual'] as $type) {
            if ($type === 'pdo') {
                $pdo->beginTransaction();
            } else {
                $pdo->exec('BEGIN IMMEDIATE');
            }
            $pdo->exec("INSERT INTO service_categories(name,slug,is_active) VALUES('External','external-$type',1)");
            $called = 0;
            try {
                $store->atomic(function () use (&$called) {
                    $called++;
                });
                $this->fail('No debe adoptar una transacción externa.');
            } catch (\LogicException $error) {
                $this->assertSame(0, $called);
            }
            $this->assertDatabaseHas('service_categories', ['slug' => 'external-'.$type]);
            if ($type === 'pdo') {
                $pdo->rollBack();
            } else {
                $pdo->exec('ROLLBACK');
            }
            $this->assertDatabaseMissing('service_categories', ['slug' => 'external-'.$type]);
        }
        $this->integrity();
    }

    public function test_structural_error_is_not_classified_as_external_or_busy_and_callback_is_not_retried(): void
    {
        $store = app(PricingStore::class);
        $calls = 0;
        try {
            $store->atomic(function () use (&$calls) {
                $calls++;
                DB::table('service_categories')->insert(['name' => 'Rolled back', 'slug' => 'rolled-back', 'is_active' => 1]);
                DB::select('SELECT missing FROM missing_table');
            });
            $this->fail('El error estructural debe conservarse.');
        } catch (\PDOException $error) {
            $this->assertSame(1, $error->errorInfo[1]);
        }
        $this->assertSame(1, $calls);
        $this->assertDatabaseMissing('service_categories', ['slug' => 'rolled-back']);
        $this->integrity();
    }
}
