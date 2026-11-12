<?php

declare(strict_types=1);

namespace Tests\Support\PricingSqlite;

use App\Payments\PriceVersion;
use App\Payments\PricingConflict;
use App\Payments\PricingStore;
use App\Payments\QuoteAcceptance;
use App\Payments\SyntheticQuote;
use Closure;
use LogicException;
use PDO;
use PDOException;
use Throwable;

/** Isolated PricingStore prototype. Never bound into Laravel or operational workflows. */
final class SqlitePricingStore implements PricingStore
{
    private bool $active = false;

    private bool $poisoned = false;

    private array $busy = [];

    private function __construct(private readonly SyntheticPricingDatabase $database, private readonly PDO $pdo, private readonly int $attempts, private readonly ?Closure $onBusy) {}

    public static function owned(SyntheticPricingDatabase $database, PDO $pdo, int $attempts = 3, ?Closure $onBusy = null): self
    {
        if (! $database->owns($pdo) || $attempts < 1 || $attempts > 3) {
            throw new LogicException('Only an owned synthetic connection and 1..3 attempts are supported.');
        }

        return new self($database, $pdo, $attempts, $onBusy);
    }

    public function atomic(callable $operation): mixed
    {
        if (! $this->database->owns($this->pdo) || $this->poisoned || $this->active || $this->pdo->inTransaction()) {
            throw new LogicException('Unknown, nested or poisoned transaction context.');
        }
        if ($this->pdo->query('PRAGMA foreign_keys')->fetchColumn() !== 1) {
            throw new LogicException('Foreign keys must remain enabled.');
        }
        $this->control('BEGIN IMMEDIATE', 'begin');
        $this->active = true;
        try {
            $result = $operation();
            $this->control('COMMIT', 'commit');

            return $result;
        } catch (Throwable $error) {
            try {
                $this->pdo->exec('ROLLBACK');
            } catch (Throwable $rollback) {
                $this->poisoned = true;
                $this->database->invalidate($this->pdo);
                throw new LogicException('Rollback could not be established; reconnect the synthetic database.', previous: $rollback);
            }
            if ($error instanceof PDOException && self::code($error) === 5) {
                throw new SqlitePricingBusy('body', 1);
            }
            throw $error;
        } finally {
            $this->active = false;
        }
    }

    /** BEGIN retries never run the callback; COMMIT retries never rerun it. */
    private function control(string $sql, string $phase): void
    {
        for ($attempt = 1; $attempt <= $this->attempts; $attempt++) {
            try {
                $this->pdo->exec($sql);

                return;
            } catch (PDOException $error) {
                // PHP 8.2 PDO does not report SQL-started transactions via inTransaction().
                // A failed BEGIN has acquired nothing: never roll back the caller's transaction.
                if ($phase === 'begin'
                    && ($error->errorInfo[0] ?? null) === 'HY000'
                    && ($error->errorInfo[1] ?? null) === 1
                    && ($error->errorInfo[2] ?? null) === 'cannot start a transaction within a transaction') {
                    throw new LogicException('An external transaction is already active; the caller retains ownership.', previous: $error);
                }
                if (self::code($error) !== 5) {
                    throw $error;
                }
                $this->busy[] = ['phase' => $phase, 'attempt' => $attempt, 'code' => $error->errorInfo[1]];
                if ($attempt === $this->attempts) {
                    throw new SqlitePricingBusy($phase, $attempt);
                }
                if ($this->onBusy !== null) {
                    ($this->onBusy)($phase, $attempt);
                }
                usleep(1000 * $attempt);
            }
        }
    }

    private static function code(PDOException $error): int
    {
        $code = $error->errorInfo[1] ?? 0;

        return is_int($code) ? $code & 255 : 0;
    }

    public function busyEvents(): array
    {
        return $this->busy;
    }

    public function latestPrice(int $serviceId): ?PriceVersion
    {
        $row = $this->row('SELECT * FROM main.b221_price_versions WHERE service_id = ? ORDER BY version DESC LIMIT 1', [$serviceId]);

        return $row === null ? null : PricingValues::hydratePrice($row);
    }

    public function priceByKey(int $serviceId, string $key): ?PriceVersion
    {
        $row = $this->row('SELECT * FROM main.b221_price_versions WHERE service_id = ? AND idempotency_key = ?', [$serviceId, PricingValues::uuid($key)]);

        return $row === null ? null : PricingValues::hydratePrice($row);
    }

    private function price(string $id): PriceVersion
    {
        $row = $this->row('SELECT * FROM main.b221_price_versions WHERE id = ?', [PricingValues::uuid($id)]);

        return $row === null ? throw new PricingConflict('The quote requires a registered price.') : PricingValues::hydratePrice($row);
    }

    public function appendPrice(PriceVersion $price): void
    {
        $this->insert('b221_price_versions', PricingValues::price($price));
    }

    public function quote(string $id): ?SyntheticQuote
    {
        $row = $this->row('SELECT * FROM main.b221_quotes WHERE id = ?', [PricingValues::uuid($id)]);
        if ($row === null) {
            return null;
        }
        $price = $this->price($row['price_version_id']);
        $quote = new SyntheticQuote(PricingValues::uuid($row['id']), PricingValues::integer($row['service_inquiry_id']),
            PricingValues::integer($row['service_id']), PricingValues::integer($row['provider_profile_id']),
            PricingValues::integer($row['client_user_id']), $price, PricingValues::uuid($row['idempotency_key']),
            PricingValues::date($row['issued_at_utc']), PricingValues::date($row['expires_at_utc']));
        foreach (PricingValues::quote($quote, $price) as $field => $value) {
            if ($row[$field] !== $value) {
                throw new PricingConflict('Stored quote snapshot mismatch.');
            }
        }

        return $quote;
    }

    public function quoteByKey(int $clientId, string $key): ?SyntheticQuote
    {
        $row = $this->row('SELECT id FROM main.b221_quotes WHERE client_user_id = ? AND idempotency_key = ?', [$clientId, PricingValues::uuid($key)]);

        return $row === null ? null : $this->quote($row['id']);
    }

    public function appendQuote(SyntheticQuote $quote): void
    {
        $this->insert('b221_quotes', PricingValues::quote($quote, $this->price($quote->price->id)));
    }

    public function acceptance(string $quoteId): ?QuoteAcceptance
    {
        $row = $this->row('SELECT * FROM main.b221_acceptances WHERE quote_id = ?', [PricingValues::uuid($quoteId)]);

        return $row === null ? null : new QuoteAcceptance(PricingValues::uuid($row['quote_id']),
            PricingValues::integer($row['client_user_id']), PricingValues::date($row['accepted_at_utc']));
    }

    public function appendAcceptance(QuoteAcceptance $acceptance): void
    {
        $this->insert('b221_acceptances', PricingValues::acceptance($acceptance));
    }

    private function row(string $sql, array $values): ?array
    {
        if (! $this->database->owns($this->pdo) || $this->poisoned) {
            throw new LogicException('Reconnect before reading an uncertain transaction context.');
        }
        $statement = $this->pdo->prepare($sql);
        $statement->execute($values);
        $row = $statement->fetch();
        $statement->closeCursor();

        return $row === false ? null : $row;
    }

    private function insert(string $table, array $values): void
    {
        if (! $this->active) {
            throw new LogicException('A write requires atomic().');
        }
        try {
            $sql = 'INSERT INTO main.'.$table.' ('.implode(',', array_keys($values)).') VALUES ('.implode(',', array_fill(0, count($values), '?')).')';
            $statement = $this->pdo->prepare($sql);
            foreach (array_values($values) as $index => $value) {
                $statement->bindValue($index + 1, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
            }
            $statement->execute();
            $statement->closeCursor();
        } catch (PDOException $error) {
            if (self::code($error) === 19) {
                throw new PricingConflict('A synthetic pricing storage constraint rejected the record.');
            }
            throw $error;
        }
    }
}
