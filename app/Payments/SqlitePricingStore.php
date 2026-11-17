<?php

declare(strict_types=1);

namespace App\Payments;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Database\Connection;
use LogicException;
use PDO;
use PDOException;
use Throwable;

/** Application adapter. Uses PDO-owned transactions, never SQL BEGIN IMMEDIATE. */
final class SqlitePricingStore implements PricingStore
{
    private ?PDO $pdo = null;

    private bool $active = false;

    private bool $invalid = false;

    private bool $failed = false;

    // Failed cleanup retains custody until process shutdown; no connection is lent again.
    private static array $quarantine = [];

    public function __construct(private readonly Connection $connection) {}

    private function connection(): PDO
    {
        if ($this->invalid || $this->connection->getDriverName() !== 'sqlite') {
            throw new LogicException('La conexión de precios no está disponible; requiere SQLite.');
        }
        $pdo = $this->connection->getPdo();
        if (! $pdo instanceof PDO) {
            throw new LogicException('La conexión de precios fue cerrada.');
        }
        if ($this->pdo !== null && $pdo !== $this->pdo) {
            throw new LogicException('La conexión de precios cambió durante su uso.');
        }
        $this->pdo = $pdo;
        if ((int) $pdo->query('PRAGMA foreign_keys')->fetchColumn() !== 1) {
            throw new LogicException('Precios requiere claves foráneas habilitadas.');
        }

        return $pdo;
    }

    public function atomic(callable $operation): mixed
    {
        $pdo = $this->connection();
        if ($this->active) {
            // Only our own nested actions (service + first price) share the unit of work.
            if (! $pdo->inTransaction() || $this->connection->transactionLevel() !== 0) {
                throw new LogicException('Se perdió la propiedad transaccional de precios.');
            }
            try {
                return $operation();
            } catch (Throwable $error) {
                $this->failed = true;
                throw $error;
            }
        }
        if ($this->connection->transactionLevel() !== 0 || $pdo->inTransaction()) {
            throw new LogicException('La transacción existente pertenece al llamador.');
        }
        try {
            if (! $pdo->beginTransaction() || ! $pdo->inTransaction()) {
                throw new LogicException('BEGIN no acreditó una transacción propia.');
            }
        } catch (Throwable $error) {
            // PHP 8.2 cannot see a manually SQL-started external transaction.
            if ($error instanceof PDOException
                && ($error->errorInfo[0] ?? null) === 'HY000'
                && ($error->errorInfo[1] ?? null) === 1
                && ($error->errorInfo[2] ?? null) === 'cannot start a transaction within a transaction') {
                throw new LogicException('La transacción manual pertenece al llamador.', previous: $error);
            }
            if ($pdo->inTransaction()) {
                $this->invalidate($pdo); // BEGIN outcome uncertain: never adopt it.
            }
            throw $error;
        }
        $this->active = true;
        $this->failed = false;
        $committing = false;
        try {
            // Obtain the SQLite writer lock BEFORE business reads or callbacks.
            // Busy admission never runs the callback. No body/commit replay.
            if ($pdo->exec('UPDATE pricing_write_lock SET id=id WHERE id=1') !== 1) {
                throw new LogicException('Falta el control de escritura de precios.');
            }
            $result = $operation();
            if ($this->connection() !== $pdo || $this->failed || ! $pdo->inTransaction() || $this->connection->transactionLevel() !== 0) {
                throw new LogicException('La unidad de precios no puede confirmarse.');
            }
            $committing = true;
            if (! $pdo->commit()) {
                throw new LogicException('COMMIT no confirmó su resultado.');
            }

            return $result;
        } catch (Throwable $error) {
            $cleanup = null;
            try {
                if (! $pdo->inTransaction()) {
                    throw new LogicException('El resultado transaccional no pudo comprobarse.');
                }
                if (! $pdo->rollBack()) {
                    throw new LogicException('Rollback no confirmó su resultado.');
                }
            } catch (Throwable $secondary) {
                $cleanup = $secondary;
            }
            if ($committing || $cleanup !== null) {
                $this->invalidate($cleanup !== null ? $pdo : null);
                throw new PricingUnavailable($error, $cleanup);
            }
            if ($error instanceof PDOException && in_array(($error->errorInfo[1] ?? 0) & 255, [5, 6], true)) {
                throw new PricingConflict('SQLite está ocupado. Reintenta con el mismo formulario.', previous: $error);
            }
            throw $error;
        } finally {
            $this->active = false;
        }
    }

    private function invalidate(?PDO $uncertain): void
    {
        $this->invalid = true;
        if ($uncertain !== null) {
            self::$quarantine[] = $uncertain;
        }
        $this->connection->disconnect();
        // A persistent worker must not reconnect an invalidated Connection object.
        $this->connection->setReconnector(static fn () => throw new LogicException('Conexión de precios invalidada.'));
    }

    private function row(string $sql, array $values): ?array
    {
        $statement = $this->connection()->prepare($sql);
        try {
            $statement->execute($values);
            $row = $statement->fetch(PDO::FETCH_ASSOC);

            return $row === false ? null : $row;
        } finally {
            $statement->closeCursor();
        }
    }

    private function insert(string $table, array $values): void
    {
        if (! $this->active) {
            throw new LogicException('La escritura requiere propiedad transaccional.');
        }
        $statement = $this->connection()->prepare('INSERT INTO '.$table.' ('.implode(',', array_keys($values)).') VALUES ('.implode(',', array_fill(0, count($values), '?')).')');
        try {
            foreach (array_values($values) as $i => $value) {
                $statement->bindValue($i + 1, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
            }
            $statement->execute();
        } finally {
            $statement->closeCursor();
        }
    }

    public static function timestamp(DateTimeInterface $date): string
    {
        return DateTimeImmutable::createFromInterface($date)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private function price(array $row): PriceVersion
    {
        return new PriceVersion($row['id'], (int) $row['service_id'], (int) $row['provider_profile_id'], (int) $row['version'], CommercialAmount::fromCents((int) $row['total_cents']), (int) $row['created_by'], $row['idempotency_key'], new DateTimeImmutable($row['created_at_utc'], new DateTimeZone('UTC')));
    }

    private function amounts(CommercialAmount $amount): array
    {
        return ['total_cents' => $amount->totalCents, 'commission_cents' => $amount->commissionCents, 'provider_cents' => $amount->providerCents, 'currency' => $amount->currency, 'duration_minutes' => $amount->durationMinutes, 'policy_version' => $amount->policyVersion, 'commission_basis_points' => $amount->commissionBasisPoints, 'provider_basis_points' => $amount->providerBasisPoints];
    }

    public function latestPrice(int $serviceId): ?PriceVersion
    {
        $row = $this->row('SELECT * FROM service_price_versions WHERE service_id=? ORDER BY version DESC LIMIT 1', [$serviceId]);

        return $row ? $this->price($row) : null;
    }

    public function priceByKey(int $serviceId, string $key): ?PriceVersion
    {
        $row = $this->row('SELECT * FROM service_price_versions WHERE service_id=? AND idempotency_key=?', [$serviceId, strtolower($key)]);

        return $row ? $this->price($row) : null;
    }

    public function appendPrice(PriceVersion $price): void
    {
        $this->insert('service_price_versions', ['id' => $price->id, 'service_id' => $price->serviceId, 'provider_profile_id' => $price->providerProfileId, 'version' => $price->version, 'created_by' => $price->createdBy, 'idempotency_key' => $price->idempotencyKey, 'created_at_utc' => self::timestamp($price->createdAt)] + $this->amounts($price->amount));
    }

    public function quote(string $id): ?SyntheticQuote
    {
        $row = $this->row('SELECT * FROM service_quotes WHERE id=?', [$id]);
        if (! $row) {
            return null;
        }
        $price = $this->price($this->row('SELECT * FROM service_price_versions WHERE id=?', [$row['price_version_id']]) ?? throw new LogicException('Falta el precio histórico.'));
        foreach ($this->amounts($price->amount) as $field => $value) {
            if ($row[$field] !== $value) {
                throw new LogicException('Snapshot monetario inconsistente.');
            }
        }

        return new SyntheticQuote($row['id'], (int) $row['service_inquiry_id'], (int) $row['service_id'], (int) $row['provider_profile_id'], (int) $row['client_user_id'], $price, $row['idempotency_key'], new DateTimeImmutable($row['issued_at_utc'], new DateTimeZone('UTC')), new DateTimeImmutable($row['expires_at_utc'], new DateTimeZone('UTC')));
    }

    public function quoteByKey(int $clientId, string $key): ?SyntheticQuote
    {
        $row = $this->row('SELECT id FROM service_quotes WHERE client_user_id=? AND idempotency_key=?', [$clientId, strtolower($key)]);

        return $row ? $this->quote($row['id']) : null;
    }

    public function appendQuote(SyntheticQuote $quote): void
    {
        $this->insert('service_quotes', ['id' => $quote->id, 'service_inquiry_id' => $quote->inquiryId, 'service_id' => $quote->serviceId, 'provider_profile_id' => $quote->providerProfileId, 'client_user_id' => $quote->clientId, 'price_version_id' => $quote->price->id, 'price_version' => $quote->price->version, 'idempotency_key' => $quote->idempotencyKey, 'issued_at_utc' => self::timestamp($quote->issuedAt), 'expires_at_utc' => self::timestamp($quote->expiresAt)] + $this->amounts($quote->price->amount));
    }

    public function acceptance(string $quoteId): ?QuoteAcceptance
    {
        $row = $this->row('SELECT * FROM quote_acceptances WHERE quote_id=?', [$quoteId]);

        return $row ? new QuoteAcceptance($row['quote_id'], (int) $row['client_user_id'], new DateTimeImmutable($row['accepted_at_utc'], new DateTimeZone('UTC'))) : null;
    }

    public function appendAcceptance(QuoteAcceptance $acceptance): void
    {
        $this->insert('quote_acceptances', ['quote_id' => $acceptance->quoteId, 'client_user_id' => $acceptance->clientId, 'accepted_at_utc' => self::timestamp($acceptance->acceptedAt)]);
    }
}
