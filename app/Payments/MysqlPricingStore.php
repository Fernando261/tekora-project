<?php

declare(strict_types=1);

namespace App\Payments;

use App\Database\PricingMySqlConnection;
use LogicException;
use PDO;
use PDOException;
use Throwable;

final class MysqlPricingStore implements PricingStore
{
    use PersistsPricing;

    private ?PDO $pdo = null;

    private bool $active = false;

    private bool $invalid = false;

    private bool $failed = false;

    // Never lend a potentially live or indeterminate connection again.
    private static array $quarantine = [];

    public function __construct(private readonly PricingMySqlConnection $connection) {}

    private function connection(): PDO
    {
        if ($this->invalid) {
            throw new LogicException('La conexión de precios fue invalidada.');
        }

        $pdo = $this->connection->getPdo();
        if (! $pdo instanceof PDO || ($this->pdo !== null && $this->pdo !== $pdo)
            || $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql'
            || ! preg_match('/\A8\.4\.\d+(?:-commercial)?\z/', $pdo->getAttribute(PDO::ATTR_SERVER_VERSION))) {
            throw new LogicException('La conexión de precios no está disponible.');
        }

        if ($this->active && (! $this->connection->ownsPricing($pdo) || ! $pdo->inTransaction())) {
            throw new LogicException('La transacción de precios ya no está activa.');
        }

        return $this->pdo = $pdo;
    }

    public function atomic(callable $operation): mixed
    {
        $pdo = $this->connection();
        if ($this->active) {
            try {
                if (! $this->connection->ownsPricing($pdo) || ! $pdo->inTransaction()) {
                    throw new LogicException('Se perdió la propiedad transaccional de precios.');
                }

                return $operation();
            } catch (Throwable $error) {
                $this->failed = true;
                throw $error;
            }
        }

        if ($this->connection->transactionLevel() !== 0 || $pdo->inTransaction()) {
            throw new LogicException('La transacción existente pertenece al llamador.');
        }
        $settings = $this->row('SELECT @@session.autocommit AS autocommit, @@session.foreign_key_checks AS fk,
            @@session.sql_mode AS modes', []);
        if (! in_array($settings['autocommit'], [1, '1'], true) || ! in_array($settings['fk'], [1, '1'], true)
            || ! array_intersect(['STRICT_TRANS_TABLES', 'STRICT_ALL_TABLES'], explode(',', $settings['modes']))
            || $pdo->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION
            || $pdo->getAttribute(PDO::ATTR_PERSISTENT)) {
            throw new LogicException('La sesión MySQL no cumple las garantías de precios.');
        }

        try {
            $this->connection->beginPricing($pdo);
        } catch (Throwable $error) {
            // A failed BEGIN is never adopted, even if the server may have opened it.
            $this->invalidate($pdo);
            throw new PricingUnavailable($error);
        }
        $this->active = true;
        $this->failed = false;
        $committing = false;
        try {
            if ($this->row('SELECT id FROM pricing_write_lock WHERE id=1 FOR UPDATE', []) === null) {
                throw new LogicException('Falta el control de escritura de precios.');
            }
            $result = $operation();
            if ($this->failed || ! $this->connection->ownsPricing($pdo) || ! $pdo->inTransaction()) {
                throw new LogicException('La unidad de precios no puede confirmarse.');
            }

            $committing = true;
            if (! $pdo->commit() || $pdo->inTransaction()) {
                throw new LogicException('COMMIT no confirmó su resultado.');
            }
            $this->connection->releasePricing();

            return $result;
        } catch (Throwable $error) {
            $diagnostic = $error instanceof PDOException ? array_slice($error->errorInfo ?? [], 0, 2) : [];
            $deadlock = $diagnostic === ['40001', 1213];
            $cleanup = null;
            try {
                if (! $this->connection->ownsPricing($pdo)) {
                    throw new LogicException('Se perdió la propiedad durante la limpieza.');
                }
                if ($pdo->inTransaction()) {
                    if (! $pdo->rollBack() || $pdo->inTransaction()) {
                        throw new LogicException('Rollback no confirmó su resultado.');
                    }
                } elseif (! $deadlock && ! $committing) {
                    // InnoDB rolls back the entire transaction on a deadlock; a lock wait
                    // normally rolls back only the failed statement. Absence alone proves neither.
                    throw new LogicException('No se pudo acreditar el rollback.');
                }
                $this->connection->releasePricing();
            } catch (Throwable $secondary) {
                $cleanup = $secondary;
            }
            if ($committing || $cleanup !== null) {
                $this->invalidate($pdo);
                throw new PricingUnavailable($error, $cleanup);
            }
            if ($deadlock || $diagnostic === ['HY000', 1205]) {
                throw new PricingConflict('La escritura de precios está ocupada. Reintenta con la misma clave.', previous: $error);
            }
            if ($diagnostic === ['45000', 1644] && in_array($error->errorInfo[2] ?? null, ['quote identity or state', 'acceptance expired or identity'], true)) {
                throw new PricingConflict('El estado de la solicitud cambió. Consulta el historial.', previous: $error);
            }
            throw $error;
        } finally {
            $this->active = false;
        }
    }

    private function invalidate(PDO $pdo): void
    {
        $this->invalid = true;
        self::$quarantine[] = $pdo;
        $this->connection->retirePricing();
    }

    private function pricingReadLock(): string
    {
        return $this->active ? ' FOR SHARE' : '';
    }

    private function normalizePricingRow(array $row): array
    {
        foreach ([
            'service_id' => PHP_INT_MAX, 'provider_profile_id' => PHP_INT_MAX, 'service_inquiry_id' => PHP_INT_MAX,
            'created_by' => PHP_INT_MAX, 'client_user_id' => PHP_INT_MAX,
            'version' => 999999999, 'price_version' => 999999999, 'total_cents' => CommercialAmount::MAX_CENTS,
            'commission_cents' => CommercialAmount::MAX_CENTS, 'provider_cents' => CommercialAmount::MAX_CENTS,
            'duration_minutes' => 60, 'commission_basis_points' => 1500, 'provider_basis_points' => 8500,
        ] as $field => $max) {
            if (! array_key_exists($field, $row)) {
                continue;
            }
            $numeric = in_array($field, ['version', 'price_version'], true) ? '(?:\.0{30})?' : '';
            $value = $row[$field];
            if (! (is_int($value) || is_string($value)) || ! preg_match('/\A(0|[1-9][0-9]*)'.$numeric.'\z/', (string) $value, $parts)
                || ($parts[1] === '0' && ! in_array($field, ['commission_cents', 'provider_cents'], true))
                || strlen($parts[1]) > strlen((string) $max)
                || (strlen($parts[1]) === strlen((string) $max) && strcmp($parts[1], (string) $max) > 0)) {
                throw new LogicException('Tipo numérico inválido en el historial de precios.');
            }
            $row[$field] = (int) $parts[1];
        }

        if (isset($row['total_cents'])) {
            $amount = CommercialAmount::fromCents($row['total_cents']);
            foreach ($this->amounts($amount) as $field => $expected) {
                if (($row[$field] ?? null) !== $expected) {
                    throw new LogicException('Desglose inválido en el historial de precios.');
                }
            }
        }

        return $row;
    }
}
