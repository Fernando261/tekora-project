<?php

declare(strict_types=1);

namespace App\Payments;

use Illuminate\Database\Connection;
use LogicException;
use PDO;
use PDOException;
use Throwable;

/** Application adapter. Uses PDO-owned transactions, never SQL BEGIN IMMEDIATE. */
final class SqlitePricingStore implements PricingStore
{
    use PersistsPricing;

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
}
