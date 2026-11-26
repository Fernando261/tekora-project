<?php

declare(strict_types=1);

namespace Tests\PaymentsSqlitePrototype;

use App\Payments\PricingConflict;
use App\Payments\QuoteAcceptance;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\PricingSqlite\PricingFixture;
use Tests\Support\PricingSqlite\PricingValues;

#[Group('pricing-sqlite-fix1')]
final class PricingSqliteFix1Test extends TestCase
{
    use PricingFixture;

    public static function originalMalformedRecords(): array
    {
        return [
            'price-id' => ['price', 'id'],
            'price-intention' => ['price', 'idempotency_key'],
            'price-created' => ['price', 'created_at_utc'],
            'quote-id' => ['quote', 'id'],
            'quote-intention' => ['quote', 'idempotency_key'],
            'quote-issued' => ['quote', 'issued_at_utc'],
            'quote-expires' => ['quote', 'expires_at_utc'],
            'acceptance-instant' => ['acceptance', 'accepted_at_utc'],
        ];
    }

    private function record(string $kind): array
    {
        $price = $this->price();
        $quote = $this->quote($price);
        if ($kind !== 'price') {
            $this->store->atomic(fn () => $this->store->appendPrice($price));
        }
        if ($kind === 'acceptance') {
            $this->store->atomic(fn () => $this->store->appendQuote($quote));
        }

        return match ($kind) {
            'price' => ['b221_price_versions', PricingValues::price($price)],
            'quote' => ['b221_quotes', PricingValues::quote($quote, $price)],
            'acceptance' => ['b221_acceptances', PricingValues::acceptance(new QuoteAcceptance($quote->id, 901, $this->at('+2 seconds')))],
        };
    }

    #[DataProvider('originalMalformedRecords')]
    public function test_original_eight_nul_variants_are_rejected_by_sql(string $kind, string $column): void
    {
        [$table, $row] = $this->record($kind);
        $before = $this->pdo->query('SELECT * FROM main.'.$table)->fetchAll();
        $row[$column] .= chr(0).'x';
        try {
            $this->rawInsert($table, $row);
        } catch (PDOException) {
            $this->assertSame($before, $this->pdo->query('SELECT * FROM main.'.$table)->fetchAll());

            return;
        }
        $this->fail('SQL must reject the original malformed '.$kind.'.'.$column.' representation.');
    }

    public static function everyCanonicalColumn(): array
    {
        return self::originalMalformedRecords() + [
            'quote-price-reference' => ['quote', 'price_version_id'],
            'acceptance-quote-reference' => ['acceptance', 'quote_id'],
        ];
    }

    #[DataProvider('everyCanonicalColumn')]
    public function test_installed_column_check_and_php_agree_on_complete_representation(string $kind, string $column): void
    {
        [$table, $row] = $this->record($kind);
        $statement = $this->pdo->prepare("SELECT sql FROM main.sqlite_schema WHERE type='table' AND name=?");
        $statement->execute([$table]);
        $ddl = $statement->fetchColumn();
        $statement->closeCursor();
        // Evaluate the actual installed column CHECK, without disabling FK or triggers.
        // This also isolates the two reference-column CHECKs from earlier FK/trigger rejections.
        $matched = preg_match('~^\h*'.preg_quote($column, '~').' TEXT [^\n]*CHECK\((.+)\),?\h*$~m', $ddl, $match);
        $this->assertSame(1, $matched);
        $predicate = $this->pdo->prepare('WITH candidate('.$column.') AS (VALUES (?)) SELECT ('.$match[1].') FROM candidate');
        $canonical = $row[$column];
        $validator = str_ends_with($column, '_utc') ? 'date' : 'uuid';
        $predicate->bindValue(1, $canonical, PDO::PARAM_STR);
        $predicate->execute();
        $this->assertSame(1, $predicate->fetchColumn());
        $predicate->closeCursor();
        $parsed = PricingValues::$validator($canonical);
        $this->assertSame($canonical, $validator === 'date' ? PricingValues::text($parsed) : $parsed);

        foreach ([$canonical.chr(0).'x', $canonical.chr(0), substr_replace($canonical, chr(0), 1, 1),
            $canonical."\t", $canonical."\n", substr_replace($canonical, chr(31), 1, 1), $canonical."\u{200B}",
            $validator === 'uuid' ? strtoupper($canonical) : str_replace(' ', 'T', $canonical)] as $invalid) {
            $predicate->bindValue(1, $invalid, PDO::PARAM_STR);
            $predicate->execute();
            $this->assertSame(0, $predicate->fetchColumn());
            $predicate->closeCursor();
            $rejectedByPhp = false;
            try {
                PricingValues::$validator($invalid);
            } catch (PricingConflict) {
                $rejectedByPhp = true;
            }
            $this->assertTrue($rejectedByPhp, 'PHP must retain its canonical validation.');
        }
    }

    public static function providerCollisions(): array
    {
        return ['same-pk-and-user' => [30, 130], 'same-pk-other-user' => [30, 140], 'other-pk-same-user' => [31, 130]];
    }

    #[DataProvider('providerCollisions')]
    public function test_provider_identity_cannot_be_replaced_by_either_unique_key(int $id, int $user): void
    {
        $this->pdo->exec('INSERT INTO main.b221_fixture_users VALUES (130),(140)');
        $this->pdo->exec('INSERT INTO main.b221_fixture_providers VALUES (30,130)');
        $before = $this->pdo->query('SELECT * FROM main.b221_fixture_providers ORDER BY id')->fetchAll();
        $recursiveTriggers = $this->pdo->query('PRAGMA recursive_triggers')->fetchColumn();
        try {
            $this->replace('b221_fixture_providers', ['id' => $id, 'user_id' => $user]);
            $this->fail('All provider identity collisions must reject REPLACE.');
        } catch (PDOException) {
            $this->assertSame($before, $this->pdo->query('SELECT * FROM main.b221_fixture_providers ORDER BY id')->fetchAll());
        }
        $this->assertSame($recursiveTriggers, $this->pdo->query('PRAGMA recursive_triggers')->fetchColumn());
        $this->pdo->exec('INSERT INTO main.b221_fixture_providers VALUES (31,140)');
        $this->assertSame(140, $this->pdo->query('SELECT user_id FROM main.b221_fixture_providers WHERE id=31')->fetchColumn());
        $this->assertSame(130, $this->pdo->query('SELECT user_id FROM main.b221_fixture_providers WHERE id=30')->fetchColumn());
    }

    public static function financialUniqueKeys(): array
    {
        return [['price-version'], ['price-intention'], ['quote-intention']];
    }

    #[DataProvider('financialUniqueKeys')]
    public function test_existing_financial_alternate_keys_still_reject_replace(string $key): void
    {
        [$price, $quote] = $this->populated();
        $table = $key === 'quote-intention' ? 'b221_quotes' : 'b221_price_versions';
        $row = $key === 'quote-intention' ? PricingValues::quote($quote, $price) : PricingValues::price($price);
        $row['id'] = $this->uuid(999);
        if ($key === 'price-version') {
            $row['idempotency_key'] = $this->uuid(1999);
        } elseif ($key === 'price-intention') {
            $row['version'] = 2;
        }
        $before = $this->pdo->query('SELECT * FROM main.'.$table)->fetchAll();
        try {
            $this->replace($table, $row);
        } catch (PDOException) {
            $this->assertSame($before, $this->pdo->query('SELECT * FROM main.'.$table)->fetchAll());

            return;
        }
        $this->fail('Existing financial alternate keys must remain append-only.');
    }

    private function replace(string $table, array $row): void
    {
        $statement = $this->pdo->prepare('INSERT OR REPLACE INTO main.'.$table.' ('.implode(',', array_keys($row)).') VALUES ('.implode(',', array_fill(0, count($row), '?')).')');
        foreach (array_values($row) as $index => $value) {
            $statement->bindValue($index + 1, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $statement->execute();
        $statement->closeCursor();
    }
}
