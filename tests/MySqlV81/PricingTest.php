<?php

namespace Tests\MySqlV81;

use App\Enums\InquiryAction;
use App\Enums\RoleName;
use App\Models\ServiceInquiry;
use App\Payments\CreatePricedService;
use App\Payments\PricingConflict;
use App\Payments\PricingStore;
use App\Payments\ServicePricing;
use App\Payments\SyntheticQuotes;
use App\Services\InquiryWorkflow;
use App\Services\ReviewWorkflow;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;

final class PricingTest extends LabTestCase
{
    private function scenario(): array
    {
        $provider = $this->account(RoleName::Provider);
        $client = $this->account(RoleName::Client);
        $service = $this->service($provider, ['service_category_id' => $this->category('pricing-'.Str::uuid())->id]);
        $inquiry = app(InquiryWorkflow::class)->open($client, $service->id, 'Solicitud de cotización sintética')['inquiry'];
        $inquiry = app(InquiryWorkflow::class)->decide($provider, $inquiry->id, InquiryAction::Accept);

        return [$provider, $client, $service, $inquiry];
    }

    private function input(int $cents = 10, int $version = 0): array
    {
        return ['amount_cents' => $cents, 'expected_version' => $version, 'idempotency_key' => (string) Str::uuid()];
    }

    private function issue(ServiceInquiry $inquiry): string
    {
        return app(SyntheticQuotes::class)->issue($inquiry->client, $inquiry->id, ['idempotency_key' => (string) Str::uuid()])->id;
    }

    public function test_exact_amount_limits_current_versions_and_immutable_historical_snapshots(): void
    {
        [$provider, $client, $service, $inquiry] = $this->scenario();
        $prices = app(ServicePricing::class);
        $quotes = app(SyntheticQuotes::class);
        $store = app(PricingStore::class);
        $this->travelTo(CarbonImmutable::parse('2030-01-04 10:00:00.123456', 'UTC'));
        foreach ([[1, 0, 1], [10, 2, 8], [30, 5, 25], [1000000000, 150000000, 850000000]] as $version => [$total, $commission, $net]) {
            $input = $this->input($total, $version);
            $price = $prices->publish($provider, $service->id, $input);
            $quote = $this->issue($inquiry);
            $this->assertSame($price->id, $prices->publish($provider, $service->id, $input)->id);
            $this->assertSame($price->id, $store->latestPrice($service->id)->id);
            $historical[$quote] = [$price->id, $total, $commission, $net];
        }
        foreach ($historical as $id => [$price, $total, $commission, $net]) {
            $quote = $quotes->view($client, $id);
            $this->assertSame([$price, $total, $commission, $net], [$quote->price->id, $quote->price->amount->totalCents, $quote->price->amount->commissionCents, $quote->price->amount->providerCents]);
            $this->assertSame('2030-01-04 10:00:00.123456', $quote->issuedAt->format('Y-m-d H:i:s.u'));
            $this->assertSame('2030-01-04 10:30:00.123456', $quote->expiresAt->format('Y-m-d H:i:s.u'));
        }
        $this->travelBack();
    }

    public function test_sql_constraints_reject_fractional_inputs_bad_breakdowns_and_noncanonical_values(): void
    {
        [$provider, $client, $service, $inquiry] = $this->scenario();
        $price = app(ServicePricing::class)->publish($provider, $service->id, $this->input());
        $table = DB::table('service_price_versions');
        $sample = (array) $table->find($price->id);
        foreach ([
            ['total_cents' => '10.000000000000000000000000000000001'], ['total_cents' => '10.5'],
            ['total_cents' => '1e1'], ['total_cents' => '-10'], ['total_cents' => '010'],
            ['total_cents' => 0], ['total_cents' => 1000000001], ['total_cents' => '18446744073709551616'],
            ['commission_cents' => '2.1'], ['commission_cents' => 1], ['provider_cents' => 7],
            ['currency' => 'mxn'], ['duration_minutes' => '60.1'], ['provider_basis_points' => '8500.1'],
            ['created_at_utc' => '2030-02-29 12:00:00.000001'], ['created_at_utc' => '2030-01-01 12:00:00'],
            ['id' => 'AAAAAAAA-0000-0000-0000-000000000000'],
        ] as $invalid) {
            $this->assertMySqlError(3819, fn () => DB::table('service_price_versions')->insert($invalid + [
                'id' => (string) Str::uuid(), 'idempotency_key' => (string) Str::uuid(), 'version' => 2,
            ] + $sample));
        }
        $this->assertMySqlError(1644, fn () => DB::table('service_price_versions')->insert([
            'id' => (string) Str::uuid(), 'idempotency_key' => (string) Str::uuid(), 'version' => 3,
        ] + $sample), 'pricing sequence');
        $this->assertMySqlError(1644, fn () => DB::table('service_price_versions')->insert([
            'id' => (string) Str::uuid(), 'idempotency_key' => (string) Str::uuid(), 'version' => 2, 'created_by' => $client->id,
        ] + $sample), 'pricing identity');
        $quote = $this->issue($inquiry);
        $snapshot = (array) DB::table('service_quotes')->find($quote);
        $this->assertMySqlError(1644, fn () => DB::table('service_quotes')->insert([
            'id' => (string) Str::uuid(), 'idempotency_key' => (string) Str::uuid(),
            'total_cents' => 11, 'provider_cents' => 9,
        ] + $snapshot), 'quote snapshot');
        foreach (['+999999 microseconds', '+86400000001 microseconds'] as $offset) {
            $expires = CarbonImmutable::parse($snapshot['issued_at_utc'], 'UTC')->modify($offset)->format('Y-m-d H:i:s.u');
            $this->assertMySqlError(3819, fn () => DB::table('service_quotes')->insert([
                'id' => (string) Str::uuid(), 'idempotency_key' => (string) Str::uuid(), 'expires_at_utc' => $expires,
            ] + $snapshot));
        }
        $this->assertMySqlError(1644, fn () => DB::table('quote_acceptances')->insert([
            'quote_id' => $quote, 'client_user_id' => $provider->id, 'accepted_at_utc' => $snapshot['issued_at_utc'],
        ]), 'acceptance expired or identity');
        app(SyntheticQuotes::class)->accept($client, $quote);
        $key = (string) Str::uuid();
        $this->assertMySqlError(1644, fn () => DB::table('service_pricing_creations')->insert([
            'actor_id' => $client->id, 'idempotency_key' => $key, 'fingerprint' => str_repeat('a', 64),
            'service_id' => $service->id, 'price_version_id' => $price->id,
        ]), 'creation identity');
        $created = app(CreatePricedService::class)->create($provider, $this->serviceData(), 10, $key);
        foreach ([
            ['pricing_write_lock', 'id', 1], ['service_price_versions', 'id', $price->id],
            ['service_quotes', 'id', $quote], ['quote_acceptances', 'quote_id', $quote],
            ['service_pricing_creations', 'service_id', $created->id],
        ] as [$name, $column, $id]) {
            $query = DB::table($name)->where($column, $id);
            $this->assertMySqlError(1644, fn () => $query->update([$column => $id]), 'pricing append only');
            $this->assertMySqlError(1644, fn () => $query->delete(), 'pricing append only');
        }
        $stranger = $this->account(RoleName::Provider);
        $other = $this->service($stranger);
        $this->assertMySqlError(1644, fn () => DB::table('services')->where('id', $service->id)->update(['provider_profile_id' => $other->provider_profile_id]), 'priced service owner immutable');
        $this->assertMySqlError(1644, fn () => DB::table('provider_profiles')->where('id', $service->provider_profile_id)->update(['user_id' => $this->account(RoleName::Provider)->id]), 'priced profile owner immutable');
        $this->assertMySqlError(1644, fn () => DB::table('service_inquiries')->where('id', $inquiry->id)->update(['client_user_id' => $stranger->id]));
        $this->assertMySqlError(1451, fn () => DB::table('services')->where('id', $created->id)->delete());
    }

    public function test_quote_lifetime_boundaries_and_expired_idempotent_acceptance(): void
    {
        [$provider, $client, $service, $inquiry] = $this->scenario();
        $this->travelTo(CarbonImmutable::parse('2030-12-31 23:59:59.123456', 'UTC'));
        app(ServicePricing::class)->publish($provider, $service->id, $this->input());
        foreach ([1, 86400] as $ttl) {
            $quotes = new SyntheticQuotes(app(PricingStore::class), $ttl);
            $start = now('UTC')->toDateTimeImmutable();
            $first = $quotes->issue($client, $inquiry->id, ['idempotency_key' => (string) Str::uuid()]);
            $second = $quotes->issue($client, $inquiry->id, ['idempotency_key' => (string) Str::uuid()]);
            $this->assertEquals($start->modify("+$ttl seconds"), $first->expiresAt);
            $this->travelTo(CarbonImmutable::instance($first->expiresAt)->subMicrosecond());
            $accepted = $quotes->accept($client, $first->id);
            $this->travelTo(CarbonImmutable::instance($first->expiresAt));
            try {
                $quotes->accept($client, $second->id);
                $this->fail('Exclusive expiry boundary allowed a new acceptance.');
            } catch (PricingConflict) {
                $this->assertNull(app(PricingStore::class)->acceptance($second->id));
            }
            $this->assertEquals($accepted, $quotes->accept($client, $first->id));
        }
        $this->travelBack();
    }

    private function startPricingWorker(array $input): array
    {
        return $this->startWorker('pricing-worker.php', json_encode($input, JSON_THROW_ON_ERROR));
    }

    private function dispatch(array $worker): int
    {
        fwrite($worker[1][0], "go\n");

        return $this->workerResult($worker)['connection'];
    }

    private function awaitStatement(int $connection, string|array ...$patterns): void
    {
        $limit = hrtime(true) + 5_000_000_000;
        $observed = [];
        do {
            $process = collect(DB::select('SHOW FULL PROCESSLIST'))->firstWhere('Id', $connection);
            $info = strtolower($process->Info ?? '');
            if ($info !== '') {
                $observed[$info] = true;
            }
            foreach ($patterns as $pattern) {
                // A native prepared statement can appear before execution reaches the lock.
                if (in_array($process->Command ?? null, ['Query', 'Execute'], true) && array_all((array) $pattern, fn ($needle) => str_contains($info, strtolower($needle)))) {
                    $this->assertSame($connection, (int) $process->Id);

                    return;
                }
            }
            usleep(10_000);
        } while (hrtime(true) < $limit);
        $this->fail('Worker never reached the blocked statement for '.json_encode($patterns, JSON_THROW_ON_ERROR).'. Observed: '.implode('; ', array_keys($observed)));
    }

    private function race(array $one, array $two): array
    {
        $workers = [];
        try {
            $workers[] = $this->startPricingWorker($one);
            $workers[] = $this->startPricingWorker($two);
            DB::transaction(function () use ($workers) {
                DB::table('pricing_write_lock')->lockForUpdate()->sole();
                foreach ($workers as $worker) {
                    $this->awaitStatement($this->dispatch($worker), 'pricing_write_lock');
                }
            });

            return array_map(function (array $worker) {
                $result = $this->workerResult($worker);
                $this->assertTrue($result['released']);
                $this->assertTrue($result['same_session']);

                return $result;
            }, $workers);
        } finally {
            foreach ($workers as $worker) {
                $this->closeWorker($worker);
            }
        }
    }

    public function test_two_publications_serialize_and_equal_requests_replay_without_advancing_the_version(): void
    {
        [$provider, , $service] = $this->scenario();
        $publish = ['operation' => 'publish', 'actor' => $provider->id, 'service' => $service->id];
        [$first, $second] = $this->race($publish + ['body' => $this->input(10)], $publish + ['body' => $this->input(30)]);
        $this->assertSame(1, (int) isset($first['conflict']) + (int) isset($second['conflict']));
        $same = $publish + ['body' => $this->input(20, 1)];
        [$first, $second] = $this->race($same, $same);
        $this->assertSame($first['id'], $second['id']);
        $this->assertSame(2, $second['version']);
        $input = $this->input(30, 2);
        [$first, $second] = $this->race($publish + ['body' => $input], $publish + ['body' => ['amount_cents' => 40] + $input]);
        $this->assertSame(1, (int) isset($first['conflict']) + (int) isset($second['conflict']));
        $this->assertSame(3, DB::table('service_price_versions')->where('service_id', $service->id)->count());
    }

    public function test_concurrent_priced_service_creation_uses_one_receipt_and_one_initial_price(): void
    {
        $provider = $this->account(RoleName::Provider);
        $profile = $this->service($provider)->providerProfile;
        $key = (string) Str::uuid();
        $request = ['operation' => 'create', 'actor' => $provider->id, 'key' => $key, 'details' => $this->serviceData(['title' => 'Servicio de creación concurrente']), 'cents' => 10];
        [$first, $second] = $this->race($request, $request);
        $this->assertSame($first['id'], $second['id']);
        $this->assertSame(1, $profile->services()->where('title', $request['details']['title'])->count());
        $receipt = DB::table('service_pricing_creations')->where('actor_id', $provider->id)->where('idempotency_key', $key)->sole();
        $this->assertSame($first['id'], $receipt->service_id);
        $this->assertSame($receipt->price_version_id, app(PricingStore::class)->latestPrice($first['id'])->id);
        $new = ['key' => (string) Str::uuid(), 'details' => $this->serviceData(['title' => 'Contenido de creación en conflicto'])] + $request;
        [$first, $second] = $this->race($new, ['cents' => 30] + $new);
        $this->assertSame(1, (int) isset($first['conflict']) + (int) isset($second['conflict']));
        $this->assertSame(1, $profile->services()->where('title', $new['details']['title'])->count());
    }

    public function test_quote_issued_during_publication_has_one_exact_historical_price_and_acceptance(): void
    {
        [$provider, $client, $service, $inquiry] = $this->scenario();
        $prices = app(ServicePricing::class);
        $initial = $prices->publish($provider, $service->id, $this->input());
        $issue = ['operation' => 'issue', 'actor' => $client->id, 'inquiry' => $inquiry->id, 'key' => (string) Str::uuid()];
        $publish = ['operation' => 'publish', 'actor' => $provider->id, 'service' => $service->id, 'body' => $this->input(30, 1)];
        [$new, $quote] = $this->race($publish, $issue);
        $snapshot = app(PricingStore::class)->quote($quote['id']);
        $this->assertContains($quote['version'], [1, 2]);
        $this->assertSame($quote['version'] === 1 ? [$initial->id, 10] : [$new['id'], 30], [$snapshot->price->id, $quote['cents']]);
        $this->assertSame($snapshot->price->amount->totalCents, $quote['cents']);
        [$first, $second] = $this->race($issue, $issue);
        $this->assertSame($quote['id'], $first['id']);
        $this->assertSame($first['id'], $second['id']);
        $other = app(InquiryWorkflow::class)->open($client, $this->service($provider)->id, 'Otra solicitud sintética')['inquiry'];
        $other = app(InquiryWorkflow::class)->decide($provider, $other->id, InquiryAction::Accept);
        $this->assertSame(true, $this->race($issue, ['inquiry' => $other->id] + $issue)[1]['conflict']);

        $accept = ['operation' => 'accept', 'actor' => $client->id, 'quote' => $quote['id']];
        [$one, $two] = $this->race($accept, $accept);
        $this->assertSame($one['at'], $two['at']);
        $this->assertSame(1, DB::table('quote_acceptances')->where('quote_id', $quote['id'])->count());
    }

    public function test_acceptance_and_issuance_use_current_parent_rows_even_after_a_snapshot_read(): void
    {
        [$provider, $client, $service, $inquiry] = $this->scenario();
        app(ServicePricing::class)->publish($provider, $service->id, $this->input());
        $quote = $this->issue($inquiry);
        $accept = ['operation' => 'accept', 'actor' => $client->id, 'quote' => $quote];
        $closed = $this->inquiryChangeWins($accept, fn () => DB::table('service_inquiries')
            ->where('id', $inquiry->id)->update(['status' => 'completed']));
        $this->assertTrue($closed['conflict']);
        $this->assertNull($closed['native']);
        $this->assertNull(app(PricingStore::class)->acceptance($quote));

        [$provider, $client, $service, $inquiry] = $this->scenario();
        app(ServicePricing::class)->publish($provider, $service->id, $this->input());
        $replacement = $this->account(RoleName::Client);
        $issue = ['operation' => 'issue', 'actor' => $client->id, 'inquiry' => $inquiry->id, 'key' => (string) Str::uuid(), 'isolation' => 'READ COMMITTED'];
        $this->assertSame(404, $this->inquiryChangeWins($issue, fn () => DB::table('service_inquiries')
            ->where('id', $inquiry->id)->update(['client_user_id' => $replacement->id]))['denied']);
        $this->assertNull(app(PricingStore::class)->quoteByKey($client->id, $issue['key']));
        $this->assertSame($replacement->id, $inquiry->fresh()->client_user_id);
    }

    private function inquiryChangeWins(array $action, callable $change): array
    {
        $worker = $this->startPricingWorker($action);
        try {
            DB::transaction(function () use ($change, $worker) {
                $this->assertSame(1, $change());
                $this->awaitStatement($this->dispatch($worker), ['lock in share mode', 'from `service_inquiries`']);
            });

            return $this->workerResult($worker);
        } finally {
            $this->closeWorker($worker);
        }
    }

    public static function eligibilityRaces(): iterable
    {
        foreach (['REPEATABLE READ', 'READ COMMITTED'] as $isolation) {
            foreach ([
                ['issue', 'pause', 404], ['accept', 'pause', 404], ['issue', 'category', 404],
                ['issue', 'provider', 404], ['accept', 'client', 403], ['publish', 'provider', 403],
            ] as [$operation, $change, $denied]) {
                foreach ([true, false] as $eligibilityFirst) {
                    $order = $eligibilityFirst ? 'eligibility first' : 'commerce first';
                    yield "$isolation $operation $change $order" => [$isolation, $operation, $change, $denied, $eligibilityFirst];
                }
            }
        }
    }

    #[DataProvider('eligibilityRaces')]
    public function test_commercial_writes_serialize_with_current_eligibility(string $isolation, string $operation, string $change, int $denied, bool $eligibilityFirst): void
    {
        [$provider, $client, $service, $inquiry] = $this->scenario();
        $input = $this->input();
        $quoteId = null;
        if ($operation !== 'publish') {
            app(ServicePricing::class)->publish($provider, $service->id, $this->input());
            if ($operation === 'accept') {
                $quoteId = $this->issue($inquiry);
            }
        }
        [$mutation, $predicate] = match ($change) {
            'pause' => [['change' => 'pause', 'target' => $service->id], ['from `services`']],
            'category' => [['change' => 'category', 'target' => $service->service_category_id], ['from `services`', '`service_categories`']],
            'provider' => [['change' => 'role', 'target' => $provider->id, 'role' => $provider->roles()->sole()->id], $operation === 'publish' ? ['from `roles`', '`role_user`'] : ['from `services`', '`role_user`']],
            'client' => [['change' => 'role', 'target' => $client->id, 'role' => $client->roles()->sole()->id], ['from `roles`', '`role_user`']],
        };
        $action = ['operation' => $operation, 'actor' => $operation === 'publish' ? $provider->id : $client->id,
            'service' => $service->id, 'quote' => $quoteId, 'inquiry' => $inquiry->id, 'body' => $input, 'key' => $input['idempotency_key']];
        $record = match ($operation) {
            'issue' => DB::table('service_quotes')->where('idempotency_key', $input['idempotency_key']),
            'accept' => DB::table('quote_acceptances')->where('quote_id', $quoteId),
            'publish' => DB::table('service_price_versions')->where('idempotency_key', $input['idempotency_key']),
        };
        DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL '.$isolation);
        $worker = $this->startPricingWorker(($eligibilityFirst ? $action : ['operation' => 'ineligible', 'timeout' => 1] + $mutation) + ['isolation' => $isolation]);
        try {
            if ($eligibilityFirst) {
                DB::transaction(function () use ($mutation, $worker, $predicate) {
                    $this->assertSame(1, match ($mutation['change']) {
                        'pause' => DB::table('services')->where('id', $mutation['target'])->update(['status' => 'paused']),
                        'category' => DB::table('service_categories')->where('id', $mutation['target'])->update(['is_active' => false]),
                        'role' => DB::table('role_user')->where('user_id', $mutation['target'])->where('role_id', $mutation['role'])->delete(),
                    });
                    $this->awaitStatement($this->dispatch($worker), ['lock in share mode', ...$predicate]);
                });
                $outcome = $this->workerResult($worker);
                $this->assertSame($denied, $outcome['denied']);
                $this->assertTrue($outcome['released']);
                $this->assertTrue($outcome['same_session']);
                $this->assertFalse($record->exists());
            } else {
                app(PricingStore::class)->atomic(function () use ($operation, $client, $provider, $inquiry, $service, $quoteId, $input, $worker) {
                    match ($operation) {
                        'issue' => app(SyntheticQuotes::class)->issue($client, $inquiry->id, ['idempotency_key' => $input['idempotency_key']]),
                        'accept' => app(SyntheticQuotes::class)->accept($client, $quoteId),
                        'publish' => app(ServicePricing::class)->publish($provider, $service->id, $input),
                    };
                    $this->dispatch($worker);
                    $this->assertSame(['native' => 1205], $this->workerResult($worker));
                });
                fwrite($worker[1][0], "retry\n");
                $this->assertSame(1, $this->workerResult($worker)['rows']);
                $this->assertSame(1, $record->count());
            }
        } finally {
            $this->closeWorker($worker);
        }
    }

    #[DataProvider('acceptanceBoundaries')]
    public function test_acceptance_clock_is_sampled_after_waiting_for_the_service(string $isolation, int $microsBeforeExpiry): void
    {
        [$provider, $client, $service, $inquiry] = $this->scenario();
        $this->travelTo(CarbonImmutable::parse('2030-01-06 12:00:00.234567', 'UTC'));
        app(ServicePricing::class)->publish($provider, $service->id, $this->input());
        $quote = app(PricingStore::class)->quote($this->issue($inquiry));
        $from = CarbonImmutable::instance($quote->expiresAt)->subSecond()->format('Y-m-d H:i:s.u');
        $afterLock = CarbonImmutable::instance($quote->expiresAt)->subMicrosecond($microsBeforeExpiry)->format('Y-m-d H:i:s.u');

        $worker = $this->startPricingWorker([
            'operation' => 'accept', 'actor' => $client->id, 'quote' => $quote->id, 'isolation' => $isolation,
            'clock' => $from, 'after_lock' => $afterLock,
        ]);
        try {
            DB::transaction(function () use ($service, $worker) {
                DB::table('services')->where('id', $service->id)->lockForUpdate()->sole();
                $this->awaitStatement($this->dispatch($worker), ['lock in share mode', 'from `services`']);
            });
            $result = $this->workerResult($worker);
            $receipt = app(PricingStore::class)->acceptance($quote->id);
            if ($microsBeforeExpiry === 0) {
                $this->assertTrue($result['conflict']);
                $this->assertNull($receipt);
            } else {
                $this->assertSame($afterLock, $result['at']);
                $this->assertSame($afterLock, $receipt->acceptedAt->format('Y-m-d H:i:s.u'));
            }
        } finally {
            $this->closeWorker($worker);
            $this->travelBack();
        }
    }

    public static function acceptanceBoundaries(): iterable
    {
        foreach (['REPEATABLE READ', 'READ COMMITTED'] as $isolation) {
            yield "$isolation at expiry" => [$isolation, 0];
            yield "$isolation one microsecond before expiry" => [$isolation, 1];
        }
    }

    public function test_historical_quote_and_acceptance_survive_later_eligibility_and_price_changes(): void
    {
        [$provider, $client, $service, $inquiry] = $this->scenario();
        app(ServicePricing::class)->publish($provider, $service->id, $this->input());
        $quotes = app(SyntheticQuotes::class);
        $input = ['idempotency_key' => $this->input()['idempotency_key']];
        $quote = $quotes->issue($client, $inquiry->id, $input);
        $receipt = $quotes->accept($client, $quote->id);
        app(ServicePricing::class)->publish($provider, $service->id, $this->input(200, 1));
        $service->update(['status' => 'paused']);
        $service->category->update(['is_active' => false]);
        $provider->roles()->detach();
        $this->travelTo(CarbonImmutable::instance($quote->expiresAt)->addSecond());
        try {
            $this->assertEquals($quote, $quotes->issue($client, $inquiry->id, $input));
            $this->assertEquals($receipt, $quotes->accept($client, $quote->id));
            $client->roles()->detach();
            foreach ([fn () => $quotes->issue($client, $inquiry->id, $input), fn () => $quotes->accept($client, $quote->id)] as $replay) {
                try {
                    $replay();
                    $this->fail('The historical record must remain private after client revocation.');
                } catch (AuthorizationException) {
                    $this->assertDatabaseHas('quote_acceptances', ['quote_id' => $quote->id, 'client_user_id' => $client->id]);
                }
            }
        } finally {
            $this->travelBack();
        }
    }

    public function test_priced_creation_rolls_back_service_and_price_on_receipt_failure(): void
    {
        $provider = $this->account(RoleName::Provider);
        $profile = $this->service($provider)->providerProfile;
        $title = 'Servicio revertido de prueba';
        $key = (string) Str::uuid();
        $create = fn () => app(CreatePricedService::class)->create($provider, $this->serviceData(['title' => $title]), 10, $key);
        DB::unprepared("CREATE TRIGGER v816_break_receipt BEFORE INSERT ON service_pricing_creations
            FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'synthetic receipt failure'");
        try {
            $before = DB::table('service_price_versions')->count();
            $this->assertMySqlError(1644, $create, 'synthetic receipt failure');
            $this->assertFalse($profile->services()->where('title', $title)->exists());
            $this->assertSame($before, DB::table('service_price_versions')->count());
            $this->assertDatabaseMissing('service_pricing_creations', ['idempotency_key' => $key]);
            $this->assertSame(0, DB::transactionLevel());
        } finally {
            DB::unprepared('DROP TRIGGER v816_break_receipt');
        }
        $this->assertSame($create()->id, $create()->id);
    }

    public function test_external_pdo_manual_and_laravel_transactions_are_never_adopted(): void
    {
        $store = app(PricingStore::class);
        $pdo = DB::connection()->getPdo();
        foreach (['pdo', 'manual', 'laravel'] as $owner) {
            match ($owner) {
                'pdo' => $pdo->beginTransaction(),
                'manual' => $pdo->exec('START TRANSACTION'),
                'laravel' => DB::beginTransaction(),
            };
            try {
                DB::table('cache')->insert(['key' => 'pricing-external-'.$owner, 'value' => 'external', 'expiration' => 0]);
                try {
                    $store->atomic(fn () => $this->fail('External transaction ran pricing callback.'));
                    $this->fail('External transaction adopted.');
                } catch (LogicException) {
                    $this->assertTrue($pdo->inTransaction());
                    $this->assertSame($owner === 'laravel' ? 1 : 0, DB::transactionLevel());
                    $this->assertDatabaseHas('cache', ['key' => 'pricing-external-'.$owner, 'value' => 'external']);
                }
            } finally {
                $owner === 'laravel' ? DB::rollBack() : $pdo->rollBack();
            }
            $this->assertDatabaseMissing('cache', ['key' => 'pricing-external-'.$owner]);
        }
        $this->assertSame('owned', $store->atomic(fn () => $store->atomic(fn () => 'owned')));
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_admission_lock_wait_runs_no_callback_and_allows_explicit_retry(): void
    {
        $worker = $this->startPricingWorker(['operation' => 'admit', 'timeout' => true]);
        try {
            DB::transaction(function () use ($worker) {
                DB::table('pricing_write_lock')->lockForUpdate()->sole();
                $this->dispatch($worker);
                $result = $this->workerResult($worker);
                $this->assertSame(['conflict' => true, 'native' => 1205, 'callbacks' => 0, 'released' => true, 'same_session' => true], $result);
            });
            $this->assertSame('retry', app(PricingStore::class)->atomic(fn () => 'retry'));
        } finally {
            $this->closeWorker($worker);
        }
    }

    public function test_lock_wait_after_partial_work_requires_a_confirmed_rollback(): void
    {
        DB::table('cache')->insert(['key' => 'pricing-timeout-parent', 'value' => 'seed', 'expiration' => 0]);
        $worker = $this->startPricingWorker(['operation' => 'wait-in-body', 'timeout' => true]);
        try {
            DB::transaction(function () use ($worker) {
                DB::table('cache')->where('key', 'pricing-timeout-parent')->update(['value' => 'parent']);
                $this->dispatch($worker);
                $this->assertSame(['conflict' => true, 'native' => 1205, 'callbacks' => 1, 'released' => true, 'same_session' => true], $this->workerResult($worker));
            });
            $this->assertDatabaseMissing('cache', ['key' => 'pricing-timeout-partial']);
            $this->assertDatabaseHas('cache', ['key' => 'pricing-timeout-parent', 'value' => 'parent']);
        } finally {
            $this->closeWorker($worker);
        }
    }

    public function test_innodb_implicit_deadlock_rollback_does_not_replay_callback(): void
    {
        $keys = array_map(fn ($n) => 'pricing-deadlock-weight-'.$n, range(1, 20));
        DB::table('cache')->insert(array_map(fn ($key) => ['key' => $key, 'value' => 'seed', 'expiration' => 0],
            [...$keys, 'pricing-deadlock-parent', 'pricing-deadlock-worker']));
        $worker = $this->startPricingWorker(['operation' => 'deadlock', 'timeout' => 10]);
        try {
            DB::transaction(function () use ($worker, $keys) {
                // Individual unique lookups avoid a range scan locking the worker's row.
                foreach ([...$keys, 'pricing-deadlock-parent'] as $key) {
                    DB::table('cache')->where('key', $key)->update(['value' => 'parent']);
                }
                $id = $this->dispatch($worker);
                $this->assertSame("locked\n", fgets($worker[1][1]));
                fwrite($worker[1][0], "cross\n");
                $this->awaitStatement($id, 'update `cache`');
                DB::table('pricing_write_lock')->lockForUpdate()->sole();
                $this->assertSame(['conflict' => true, 'native' => 1213, 'callbacks' => 1, 'released' => true, 'same_session' => true], $this->workerResult($worker));
            });
            $this->assertDatabaseHas('cache', ['key' => 'pricing-deadlock-parent', 'value' => 'parent']);
            $this->assertDatabaseHas('cache', ['key' => 'pricing-deadlock-worker', 'value' => 'seed']);
        } finally {
            $this->closeWorker($worker);
        }
    }

    public function test_api_and_web_write_boundaries_sanitize_errors_and_keep_history_private(): void
    {
        // This socket-only lab validates server responses. Vite is built separately.
        $this->withoutVite();
        [$provider, $client, $service, $inquiry] = $this->scenario();
        $bearer = fn ($actor) => ['Authorization' => 'Bearer '.$actor->createToken('mobile:v816', ['client:read'], now()->addDay())->plainTextToken];
        $headers = $bearer($client);
        $url = '/api/v1/inquiries/'.$inquiry->id.'/quotes';
        $key = (string) Str::uuid();
        $this->postJson($url, ['idempotency_key' => $key])->assertUnauthorized();
        $this->actingAs($provider, 'web')->post(route('provider-services.price', $service), [
            'price_mxn' => '0.10', 'expected_version' => 0, 'idempotency_key' => (string) Str::uuid(),
        ])->assertStatus(303);
        auth()->forgetGuards();
        DB::unprepared("CREATE TRIGGER v816_break_quote BEFORE INSERT ON service_quotes FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PRIVATE_V816_QUOTE'");
        try {
            $this->postJson($url, ['idempotency_key' => $key], $headers)->assertStatus(503)
                ->assertJsonPath('error.code', 'temporarily_unavailable')->assertDontSee('PRIVATE_V816_QUOTE');
            $this->actingAs($client, 'web')->post(route('quotes.issue', $inquiry), ['idempotency_key' => $key])
                ->assertStatus(503)->assertDontSee('PRIVATE_V816_QUOTE');
            $this->assertNull(app(PricingStore::class)->quoteByKey($client->id, $key));
        } finally {
            DB::unprepared('DROP TRIGGER v816_break_quote');
        }

        auth()->forgetGuards();
        $id = $this->postJson($url, ['idempotency_key' => $key], $headers)->assertOk()
            ->assertJsonPath('data.amount.total_cents', 10)->assertJsonPath('data.amount.commission_cents', 2)
            ->assertJsonPath('data.amount.provider_cents', 8)->assertJsonMissingPath('data.idempotency_key')->json('data.id');
        $quoteUrl = '/api/v1/quotes/'.$id;
        auth()->forgetGuards();
        $visitor = $this->account(RoleName::Client);
        $this->getJson($quoteUrl, $bearer($visitor))->assertNotFound();
        $this->actingAs($visitor, 'web')->get(route('quotes.show', $id))->assertForbidden()->assertDontSee($inquiry->message);
        $this->actingAs($client, 'web')->post(route('quotes.accept', $id), ['acknowledge' => 1])->assertStatus(303);
        auth()->forgetGuards();
        $this->getJson($url, $headers)->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $id);
        $this->postJson($quoteUrl.'/accept', ['acknowledge' => true], $headers)->assertOk()->assertJsonPath('data.acceptance.state', 'accepted');
        $this->getJson($quoteUrl.'/acceptance', $headers)->assertOk()->assertJsonPath('data.state', 'accepted');
        $this->assertDatabaseMissing('service_reservations', ['service_inquiry_id' => $inquiry->id]);
        $this->assertFalse(DB::getSchemaBuilder()->hasTable('reservation_holds'));
    }

    public function test_catalog_renders_exact_price_and_public_reviews_without_b2(): void
    {
        // This socket-only lab validates server responses. Vite is built separately.
        $this->withoutVite();
        [$provider, $client, $service, $inquiry] = $this->scenario();
        $reservation = $this->completedReservation($inquiry, $provider);
        $this->travelTo(CarbonImmutable::parse('2030-01-08 12:00:00', 'UTC'));
        app(ReviewWorkflow::class)->publish($client, $reservation->id, ['rating' => 5, 'comment' => 'Reseña pública V816']);
        app(ServicePricing::class)->publish($provider, $service->id, $this->input(9999));
        $this->get(route('catalog.index', ['q' => $service->title]))->assertOk()->assertSee('99.99');
        $this->get(route('catalog.show', $service))->assertOk()->assertSee('99.99')->assertSee('Reseña pública V816')
            ->assertDontSee($client->name)->assertDontSee($client->email);
        $this->getJson('/api/v1/services/'.$service->id)->assertOk()
            ->assertJsonPath('data.id', $service->id)->assertJsonMissingPath('data.client_user_id');
        $this->travelBack();
    }
}
