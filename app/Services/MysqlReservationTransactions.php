<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\PricingMySqlConnection;
use App\Exceptions\ReservationFailure;
use App\Exceptions\ReservationStorageUncertain;
use App\Support\BookingClock;
use LogicException;
use PDO;
use PDOException;
use Throwable;

final class MysqlReservationTransactions
{
    private static array $quarantine = [];

    public function run(PricingMySqlConnection $connection, callable $operation): mixed
    {
        $pdo = $connection->getPdo();
        $parent = $connection->transactionLevel();
        if (! $pdo instanceof PDO || $connection->hasPricingTransaction() || $connection->hasReservationTransaction()
            || $pdo->inTransaction() !== ($parent > 0)) {
            throw new ReservationFailure('La conexión está ocupada por una transacción ajena a reservas.', 503);
        }
        $settings = $connection->selectOne('SELECT @@session.foreign_key_checks fk, @@session.autocommit auto_commit, @@session.time_zone zone, @@session.sql_mode modes');
        if (PHP_INT_SIZE !== 8 || (int) $settings->fk !== 1 || (int) $settings->auto_commit !== 1 || $settings->zone !== '+00:00'
            || ! array_intersect(['STRICT_TRANS_TABLES', 'STRICT_ALL_TABLES'], explode(',', $settings->modes))
            || ! preg_match('/^8\.4\.\d+(?:-commercial)?$/D', $pdo->getAttribute(PDO::ATTR_SERVER_VERSION))
            || $pdo->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION || $pdo->getAttribute(PDO::ATTR_PERSISTENT)) {
            throw new ReservationFailure('La sesión no cumple las garantías InnoDB de reservas.', 503);
        }

        $savepoint = 'tekora_b2_'.bin2hex(random_bytes(12));
        try {
            $connection->beginReservationWork($pdo, $parent, $savepoint);
        } catch (Throwable $error) {
            $failure = new ReservationStorageUncertain($error);
            $this->retire($connection, $pdo, $parent, $failure);
            throw $failure;
        }
        $finishing = false;
        try {
            $this->admit($connection);
            try {
                $result = $operation();
            } catch (ReservationFailure $rejection) {
                if ($rejection->status >= 500) {
                    throw $rejection;
                }
                $result = $rejection;
            }
            if (! $connection->ownsReservationWork($pdo, $parent) || ! $pdo->inTransaction()) {
                throw new LogicException('Se perdió la unidad de reservas.');
            }
            $finishing = true;
            if ($parent > 0) {
                // RELEASE does not commit the caller's physical transaction.
                if ($pdo->exec('RELEASE SAVEPOINT '.$savepoint) === false || ! $pdo->inTransaction()) {
                    throw new LogicException('No se acreditó la liberación del savepoint.');
                }
            } elseif (! $pdo->commit() || $pdo->inTransaction()) {
                throw new LogicException('COMMIT no acreditó su resultado.');
            }
            $connection->finishReservationWork($parent);
        } catch (Throwable $error) {
            $diagnostic = $error instanceof PDOException ? array_slice($error->errorInfo ?? [], 0, 2) : [];
            $deadlock = $diagnostic === ['40001', 1213];
            $cleanup = null;
            try {
                if (! $connection->ownsReservationWork($pdo, $parent)) {
                    throw new LogicException('No se acreditó la propiedad durante la limpieza.');
                }
                if ($deadlock) {
                    // An ERR packet does not necessarily refresh mysqlnd's transaction-status flag.
                    // Read only server status on the same physical session after the engine abort.
                    $status = $pdo->query('SELECT 1');
                    if ($status === false || ! $status->closeCursor() || $pdo->inTransaction()) {
                        throw new LogicException('El servidor no acreditó el rollback implícito.');
                    }
                } elseif ($pdo->inTransaction()) {
                    if ($parent > 0) {
                        if ($pdo->exec('ROLLBACK TO SAVEPOINT '.$savepoint) === false
                            || $pdo->exec('RELEASE SAVEPOINT '.$savepoint) === false || ! $pdo->inTransaction()) {
                            throw new LogicException('No se acreditó el rollback del savepoint.');
                        }
                    } elseif (! $pdo->rollBack() || $pdo->inTransaction()) {
                        throw new LogicException('Rollback no acreditado.');
                    }
                } elseif (! $finishing || $parent > 0) {
                    throw new LogicException('No se acreditó el rollback ni el savepoint.');
                }
                $connection->finishReservationWork($parent);
            } catch (Throwable $secondary) {
                $cleanup = $secondary;
            }
            if ($finishing || $cleanup !== null) {
                $failure = new ReservationStorageUncertain($error, $cleanup);
                $this->retire($connection, $pdo, $parent, $failure);
                throw $failure;
            }
            if ($deadlock || $diagnostic === ['HY000', 1205]) {
                $message = $deadlock && $parent > 0
                    ? 'InnoDB revirtió la transacción del llamador. Finaliza esa unidad antes de reintentar.'
                    : 'Hay un conflicto de escritura. Consulta el estado y reintenta con la misma clave.';
                $failure = new ReservationFailure($message, 409, $error);
                if ($deadlock && $parent > 0) {
                    $connection->requireCallerRollback($parent, $pdo, $failure);
                }
                throw $failure;
            }
            throw $error;
        }
        if ($result instanceof ReservationFailure) {
            throw $result;
        }

        return $result;
    }

    private function admit(PricingMySqlConnection $connection): void
    {
        $clock = $connection->table('reservation_hold_clock')->where('id', 1)->lockForUpdate()->first();
        // Prices and their accepted quotes cannot change between proposal admission and persistence.
        $pricing = $connection->table('pricing_write_lock')->where('id', 1)->lockForUpdate()->first();
        if ($clock === null || $pricing === null) {
            throw new LogicException('Falta la admisión obligatoria de reservas.');
        }
        $now = BookingClock::micros($connection);
        if ($now < (int) $clock->high_water_us) {
            throw new ReservationFailure('El reloj del servidor retrocedió. La operación queda bloqueada hasta recuperar una hora segura.', 503);
        }
        if ($connection->table('reservation_holds')->select('service_reservation_id')->sharedLock()->first() !== null) {
            $connection->table('reservation_hold_clock')->where('id', 1)->update(['high_water_us' => $now]);
            $connection->table('reservation_holds')->where('state', 'held')->where('expires_at_us', '<=', $now)->update([
                'state' => 'expired', 'version' => $connection->raw('version+1'), 'last_actor_user_id' => null,
                'closed_at_us' => $connection->raw('expires_at_us'),
            ]);
        }
    }

    private function retire(PricingMySqlConnection $connection, PDO $pdo, int $parent, ReservationStorageUncertain $failure): void
    {
        if ($parent > 0) {
            $connection->requireCallerRollback($parent, $pdo, $failure);

            return;
        }
        $connection->finishReservationWork(0);
        self::$quarantine[] = $pdo;
        $connection->retirePricing();
    }
}
