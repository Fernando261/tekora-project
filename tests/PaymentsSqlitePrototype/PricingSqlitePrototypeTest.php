<?php

declare(strict_types=1);

namespace Tests\PaymentsSqlitePrototype;

use App\Payments\PriceVersion;
use App\Payments\PricingConflict;
use App\Payments\PricingStore;
use App\Payments\QuoteAcceptance;
use DateTimeImmutable;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\PricingSqlite\PricingFixture;
use Tests\Support\PricingSqlite\PricingValues;

final class PricingSqlitePrototypeTest extends TestCase
{
    use PricingFixture;

    public function test_schema_is_fresh_strict_and_contains_no_operational_tables(): void
    {
        $this->assertInstanceOf(PricingStore::class, $this->store);
        $names = $this->pdo->query("SELECT name FROM main.sqlite_schema WHERE type='table' ORDER BY name")->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame(['b221_acceptances', 'b221_fixture_inquiries', 'b221_fixture_providers', 'b221_fixture_services', 'b221_fixture_users', 'b221_price_versions', 'b221_quotes'], $names);
        $this->assertSame(221, $this->pdo->query('PRAGMA user_version')->fetchColumn());
        $this->assertSame(1, $this->pdo->query('PRAGMA foreign_keys')->fetchColumn());
        $this->assertSame('delete', $this->pdo->query('PRAGMA journal_mode')->fetchColumn());
        $this->assertSame(2, $this->pdo->query('PRAGMA synchronous')->fetchColumn());
        foreach ($this->pdo->query('PRAGMA main.table_list')->fetchAll() as $table) {
            if (str_starts_with($table['name'], 'b221_')) {
                $this->assertSame(1, $table['strict']);
            }
        }
    }

    public function test_reconstitution_is_by_value_and_preserves_microseconds_and_all_amounts(): void
    {
        [$price, $quote, $acceptance] = $this->populated();
        $reopened = $this->lab->store();
        $stored = $reopened->quote($quote->id);
        $this->assertNotSame($price, $stored->price);
        $this->assertEquals($price, $stored->price);
        $this->assertEquals($quote, $stored);
        $this->assertEquals($acceptance, $reopened->acceptance($quote->id));
        $this->assertSame('123456', $stored->issuedAt->format('u'));
        $this->assertSame('UTC', $stored->issuedAt->getTimezone()->getName());
        $second = $this->quote($stored->price, inquiry: 104);
        $reopened->atomic(fn () => $reopened->appendQuote($second));
        $this->assertEquals($second, $reopened->quote($second->id));
    }

    public static function amounts(): array
    {
        return [[1, 0], [3, 0], [4, 1], [10, 2], [30, 5], [99, 15], [101, 15], [999_999_999, 150_000_000], [1_000_000_000, 150_000_000]];
    }

    #[DataProvider('amounts')]
    public function test_sql_and_dto_preserve_exact_15_percent_and_residual(int $total, int $commission): void
    {
        $price = $this->price(total: $total);
        $quote = $this->quote($price);
        $this->store->atomic(function () use ($price, $quote) {
            $this->store->appendPrice($price);
            $this->store->appendQuote($quote);
        });
        $row = $this->pdo->query('SELECT amount_cents,commission_cents,provider_cents,typeof(amount_cents) AS type FROM main.b221_quotes')->fetch();
        $this->assertSame([$total, $commission, $total - $commission, 'integer'], array_values($row));
        $this->assertSame($total, $this->store->quote($quote->id)->price->amount->totalCents);
    }

    public function test_later_price_does_not_change_quote_or_acceptance(): void
    {
        [, $quote, $accepted] = $this->populated();
        $before = $this->pdo->query('SELECT * FROM main.b221_quotes')->fetchAll();
        $next = $this->price(version: 2, total: 200_000);
        $this->store->atomic(fn () => $this->store->appendPrice($next));
        $this->assertSame(2, $this->store->latestPrice(1001)->version);
        $this->assertSame(100_000, $this->store->quote($quote->id)->price->amount->totalCents);
        $this->assertSame($before, $this->pdo->query('SELECT * FROM main.b221_quotes')->fetchAll());
        $this->assertEquals($accepted, $this->store->acceptance($quote->id));
    }

    public function test_client_intention_scopes_and_historical_replays_are_preserved(): void
    {
        [$price, $quote, $accepted] = $this->populated();
        $other = $this->quote($price, 902, 103, $quote->idempotencyKey);
        $this->store->atomic(fn () => $this->store->appendQuote($other));
        $reopened = $this->lab->store();
        $this->assertSame($quote->id, $reopened->quoteByKey(901, $quote->idempotencyKey)->id);
        $this->assertSame($other->id, $reopened->quoteByKey(902, $quote->idempotencyKey)->id);
        $this->assertNull($reopened->quoteByKey(110, $quote->idempotencyKey));
        $this->assertEquals($price, $reopened->priceByKey(1001, $price->idempotencyKey));
        $this->assertEquals($accepted, $reopened->atomic(fn () => $reopened->acceptance($quote->id)));
        $this->assertSame(1, $this->pdo->query('SELECT count(*) FROM main.b221_acceptances')->fetchColumn());
    }

    public static function badPrices(): array
    {
        return [['amount_cents', 0], ['amount_cents', -1], ['amount_cents', 1_000_000_001], ['amount_cents', '1.5'], ['amount_cents', null],
            ['version', 0], ['version', 2], ['version', '1.5'], ['created_by', 901], ['provider_profile_id', 20], ['currency', 'USD'],
            ['duration_minutes', 30], ['id', 'ABCDEFAB-ABCD-4000-8000-000000000001'], ['id', 'not-uuid'],
            ['created_at_utc', '2030-02-30 08:00:00.123456'], ['created_at_utc', '2030-01-01 08:00:00'], ['created_at_utc', '2030-01-01 08:00:00.12345x']];
    }

    #[DataProvider('badPrices')]
    public function test_sql_rejects_invalid_price_records(string $field, mixed $value): void
    {
        $row = PricingValues::price($this->price());
        $row[$field] = $value;
        try {
            $this->rawInsert('b221_price_versions', $row);
            $this->fail('Invalid SQL price must be rejected.');
        } catch (PDOException) {
            $this->assertSame(0, $this->pdo->query('SELECT count(*) FROM main.b221_price_versions')->fetchColumn());
        }
    }

    public static function badQuotes(): array
    {
        return [['amount_cents', 101], ['commission_cents', 1], ['provider_cents', 1], ['commission_bps', 1499], ['synthetic', 0],
            ['price_version', 2], ['price_version', '1.5'], ['client_user_id', 902], ['service_inquiry_id', 102],
            ['price_version_id', 'abcdefab-abcd-4000-8000-000000999999'], ['provider_profile_id', 20],
            ['expires_at_utc', '2030-01-01 08:00:01.123456'], ['expires_at_utc', '2030-01-01 08:00:02.123455'],
            ['expires_at_utc', '2030-01-02 08:00:01.123457'], ['issued_at_utc', '2030-01-01 07:59:59.123456'],
            ['issued_at_utc', '2030-02-30 08:00:00.123456'], ['id', 'ABCDEFAB-ABCD-4000-8000-000000000001'],
            ['policy_version', 'unknown'], ['currency', 'USD'], ['duration_minutes', 30], ['client_user_id', null]];
    }

    #[DataProvider('badQuotes')]
    public function test_sql_rejects_inconsistent_quotes(string $field, mixed $value): void
    {
        $price = $this->price();
        $this->store->atomic(fn () => $this->store->appendPrice($price));
        $row = PricingValues::quote($this->quote($price), $price);
        $row[$field] = $value;
        try {
            $this->rawInsert('b221_quotes', $row);
            $this->fail('Invalid quote must be rejected by SQL.');
        } catch (PDOException) {
            $this->assertSame(0, $this->pdo->query('SELECT count(*) FROM main.b221_quotes')->fetchColumn());
        }
    }

    public static function badAcceptances(): array
    {
        return [['client_user_id', 902], ['quote_id', 'abcdefab-abcd-4000-8000-000000999999'],
            ['accepted_at_utc', '2030-01-01 08:00:01.123455'], ['accepted_at_utc', '2030-01-01 08:30:01.123456'],
            ['accepted_at_utc', '2030-01-01 08:30:01.123457'], ['accepted_at_utc', null]];
    }

    #[DataProvider('badAcceptances')]
    public function test_sql_rejects_inconsistent_acceptances(string $field, mixed $value): void
    {
        $price = $this->price();
        $quote = $this->quote($price);
        $this->store->atomic(function () use ($price, $quote) {
            $this->store->appendPrice($price);
            $this->store->appendQuote($quote);
        });
        $row = PricingValues::acceptance(new QuoteAcceptance($quote->id, 901, $this->at('+2 seconds')));
        $row[$field] = $value;
        try {
            $this->rawInsert('b221_acceptances', $row);
            $this->fail('Invalid acceptance must be rejected by SQL.');
        } catch (PDOException) {
            $this->assertNull($this->store->acceptance($quote->id));
        }
    }

    public static function mutations(): array
    {
        $rows = [];
        foreach (['b221_price_versions', 'b221_quotes', 'b221_acceptances'] as $table) {
            foreach (['UPDATE', 'DELETE', 'REPLACE'] as $operation) {
                $rows[] = [$table, $operation];
            }
        }

        return $rows;
    }

    #[DataProvider('mutations')]
    public function test_all_commercial_history_rejects_update_delete_and_replace(string $table, string $operation): void
    {
        $this->populated();
        $before = $this->pdo->query('SELECT * FROM main.'.$table)->fetchAll();
        $sql = match ($operation) {
            'UPDATE' => 'UPDATE main.'.$table.' SET '.($table === 'b221_acceptances' ? 'quote_id=quote_id' : 'id=id'),
            'DELETE' => 'DELETE FROM main.'.$table,
            'REPLACE' => 'INSERT OR REPLACE INTO main.'.$table.' SELECT * FROM main.'.$table,
        };
        try {
            $this->pdo->exec($sql);
            $this->fail('Historical mutation must fail.');
        } catch (PDOException) {
            $this->assertSame($before, $this->pdo->query('SELECT * FROM main.'.$table)->fetchAll());
        }
    }

    public function test_rehydrated_price_cannot_disguise_changed_embedded_metadata(): void
    {
        $price = $this->price();
        $this->store->atomic(fn () => $this->store->appendPrice($price));
        $forged = new PriceVersion($price->id, $price->serviceId, $price->providerProfileId, $price->version,
            $price->amount, $price->createdBy, $this->uuid(999), $price->createdAt);
        $this->expectException(PricingConflict::class);
        $this->store->atomic(fn () => $this->store->appendQuote($this->quote($forged)));
    }

    public function test_offset_instants_are_canonicalized_without_truncation(): void
    {
        $this->assertSame('2030-01-01 08:00:00.123456', PricingValues::text(new DateTimeImmutable('2030-01-01T02:00:00.123456-06:00')));
        $this->expectException(PricingConflict::class);
        PricingValues::date('2030-01-01T08:00:00.123456Z');
    }

    public function test_different_ids_cannot_reuse_a_price_or_quote_intention(): void
    {
        [$price, $quote] = $this->populated();
        $next = $this->price(version: 2, key: $price->idempotencyKey);
        try {
            $this->store->atomic(fn () => $this->store->appendPrice($next));
            $this->fail('A price intention cannot name a later version.');
        } catch (PricingConflict) {
            $this->assertSame(1, $this->store->latestPrice(1001)->version);
        }
        $other = $this->quote($price, inquiry: 104, key: $quote->idempotencyKey);
        try {
            $this->store->atomic(fn () => $this->store->appendQuote($other));
            $this->fail('A client intention cannot name another quote.');
        } catch (PricingConflict) {
            $this->assertSame($quote->id, $this->store->quoteByKey(901, $quote->idempotencyKey)->id);
        }
    }

    public function test_duplicate_acceptance_cannot_change_original_instant(): void
    {
        [, $quote, $original] = $this->populated();
        try {
            $this->store->atomic(fn () => $this->store->appendAcceptance(new QuoteAcceptance($quote->id, 901, $this->at('+3 seconds'))));
            $this->fail('A second acceptance must not replace history.');
        } catch (PricingConflict) {
            $this->assertEquals($original, $this->store->acceptance($quote->id));
        }
    }

    public function test_acceptance_is_allowed_at_issue_and_one_microsecond_before_deadline(): void
    {
        $price = $this->price();
        $first = $this->quote($price);
        $second = $this->quote($price, inquiry: 104);
        $this->store->atomic(function () use ($price, $first, $second) {
            $this->store->appendPrice($price);
            $this->store->appendQuote($first);
            $this->store->appendQuote($second);
            $this->store->appendAcceptance(new QuoteAcceptance($first->id, 901, $first->issuedAt));
            $this->store->appendAcceptance(new QuoteAcceptance($second->id, 901, $second->expiresAt->modify('-1 microsecond')));
        });
        $this->assertEquals($first->issuedAt, $this->store->acceptance($first->id)->acceptedAt);
        $this->assertEquals($second->expiresAt->modify('-1 microsecond'), $this->store->acceptance($second->id)->acceptedAt);
    }

    public function test_provider_cannot_be_client_of_its_own_quote(): void
    {
        $price = $this->price();
        $this->store->atomic(fn () => $this->store->appendPrice($price));
        $this->expectException(PricingConflict::class);
        $this->store->atomic(fn () => $this->store->appendQuote($this->quote($price, 110, 202)));
    }

    public function test_same_price_intention_is_recovered_after_connection_restart_without_a_duplicate(): void
    {
        $price = $this->price();
        $this->store->atomic(fn () => $this->store->appendPrice($price));
        $newConnection = $this->lab->store();
        // The B1 consumer protocol reads the receipt before attempting another append.
        $replay = $newConnection->atomic(function () use ($newConnection, $price) {
            $existing = $newConnection->priceByKey($price->serviceId, $price->idempotencyKey);
            if ($existing !== null) {
                return $existing;
            }
            $newConnection->appendPrice($price);

            return $price;
        });
        $this->assertEquals($price, $replay);
        $this->assertNotSame($price, $replay);
        $this->assertSame(1, $this->pdo->query('SELECT count(*) FROM main.b221_price_versions')->fetchColumn());
    }

    public function test_price_intentions_are_scoped_per_service(): void
    {
        $first = $this->price();
        $other = $this->price(service: 2001, key: $first->idempotencyKey);
        $this->store->atomic(function () use ($first, $other) {
            $this->store->appendPrice($first);
            $this->store->appendPrice($other);
        });
        $this->assertEquals($first, $this->store->priceByKey(1001, $first->idempotencyKey));
        $this->assertEquals($other, $this->store->priceByKey(2001, $first->idempotencyKey));
    }

    public function test_fixture_identity_cannot_be_replaced_to_reinterpret_a_snapshot(): void
    {
        $this->populated();
        foreach (['users', 'providers', 'services', 'inquiries'] as $suffix) {
            $table = 'b221_fixture_'.$suffix;
            $before = $this->pdo->query('SELECT * FROM main.'.$table.' ORDER BY id')->fetchAll();
            try {
                $this->pdo->exec('INSERT OR REPLACE INTO main.'.$table.' SELECT * FROM main.'.$table);
                $this->fail('Fixture identities are append-only too.');
            } catch (PDOException) {
                $this->assertSame($before, $this->pdo->query('SELECT * FROM main.'.$table.' ORDER BY id')->fetchAll());
            }
        }
    }
}
