<?php

declare(strict_types=1);

namespace Tests\Support\PricingSqlite;

use App\Payments\CommercialAmount;
use App\Payments\PriceVersion;
use App\Payments\QuoteAcceptance;
use App\Payments\SyntheticQuote;
use DateTimeImmutable;
use PDO;

trait PricingFixture
{
    private SyntheticPricingDatabase $lab;

    private PDO $pdo;

    private SqlitePricingStore $store;

    private int $serial = 100;

    protected function setUp(): void
    {
        $this->lab = SyntheticPricingDatabase::create();
        $this->pdo = $this->lab->connect();
        $this->pdo->exec('INSERT INTO main.b221_fixture_users VALUES (110),(120),(901),(902)');
        $this->pdo->exec('INSERT INTO main.b221_fixture_providers VALUES (10,110),(20,120)');
        $this->pdo->exec('INSERT INTO main.b221_fixture_services VALUES (1001,10),(1002,10),(2001,20)');
        $this->pdo->exec('INSERT INTO main.b221_fixture_inquiries VALUES (101,1001,901),(102,1002,902),(103,1001,902),(104,1001,901),(201,2001,901),(202,1001,110)');
        $this->store = $this->lab->store($this->pdo);
    }

    protected function tearDown(): void
    {
        $this->assertSame('ok', $this->pdo->query('PRAGMA integrity_check')->fetchColumn());
        $this->assertSame([], $this->pdo->query('PRAGMA foreign_key_check')->fetchAll());
    }

    private function uuid(int $id): string
    {
        return sprintf('abcdefab-abcd-4000-8000-%012d', $id);
    }

    private function at(string $offset = '+0 seconds'): DateTimeImmutable
    {
        return (new DateTimeImmutable('2030-01-01T08:00:00.123456Z'))->modify($offset);
    }

    private function price(int $version = 1, int $total = 100_000, int $service = 1001, ?string $key = null): PriceVersion
    {
        $id = ++$this->serial;

        return new PriceVersion($this->uuid($id), $service, $service === 2001 ? 20 : 10, $version,
            CommercialAmount::fromCents($total), $service === 2001 ? 120 : 110, $key ?? $this->uuid($id + 1000), $this->at());
    }

    private function quote(PriceVersion $price, int $client = 901, int $inquiry = 101, ?string $key = null): SyntheticQuote
    {
        $id = ++$this->serial;

        return new SyntheticQuote($this->uuid($id), $inquiry, $price->serviceId, $price->providerProfileId,
            $client, $price, $key ?? $this->uuid($id + 1000), $this->at('+1 second'), $this->at('+1801 seconds'));
    }

    private function populated(): array
    {
        $price = $this->price();
        $quote = $this->quote($price);
        $acceptance = new QuoteAcceptance($quote->id, 901, $this->at('+2 seconds'));
        $this->store->atomic(function () use ($price, $quote, $acceptance) {
            $this->store->appendPrice($price);
            $this->store->appendQuote($quote);
            $this->store->appendAcceptance($acceptance);
        });

        return [$price, $quote, $acceptance];
    }

    private function rawInsert(string $table, array $values, ?PDO $connection = null): void
    {
        $connection ??= $this->pdo;
        $statement = $connection->prepare('INSERT INTO main.'.$table.' ('.implode(',', array_keys($values)).') VALUES ('.implode(',', array_fill(0, count($values), '?')).')');
        foreach (array_values($values) as $index => $value) {
            $statement->bindValue($index + 1, $value, match (true) {
                is_int($value) => PDO::PARAM_INT,
                $value === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            });
        }
        $statement->execute();
        $statement->closeCursor();
    }
}
