<?php

namespace App\Services;

use App\Database\PricingMySqlConnection;
use App\Exceptions\ReservationFailure;
use App\Exceptions\ReservationStorageUncertain;
use App\Support\BookingClock;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use LogicException;
use PDO;
use PDOException;
use Throwable;

/** One writer admission and one execution of business code per booking command. */
final class ReservationTransactions
{
    private static array $quarantine = [];

    public function run(callable $operation): mixed
    {
        $connection = DB::connection();
        if ($connection instanceof PricingMySqlConnection) {
            return app(MysqlReservationTransactions::class)->run($connection, $operation);
        }
        if ($connection->getDriverName() !== 'sqlite') {
            throw new ReservationFailure('Motor de reservas no soportado.', 503);
        }
        $pdo = $connection->getPdo();
        if ($pdo instanceof PDO && $connection->transactionLevel() > 0 && $pdo->inTransaction()) {
            return $this->inSavepoint($operation);
        }
        if (! $pdo instanceof PDO || $connection->transactionLevel() !== 0 || $pdo->inTransaction()) {
            throw new ReservationFailure('La conexión está ocupada por otra transacción; no se adoptó.', 503);
        }
        if ((int) $pdo->query('PRAGMA foreign_keys')->fetchColumn() !== 1) {
            throw new ReservationFailure('La conexión no cumple las garantías de reservas.', 503);
        }
        try {
            $connection->beginTransaction();
        } catch (Throwable $error) {
            if ($error instanceof PDOException && ($error->errorInfo[0] ?? null) === 'HY000'
                && ($error->errorInfo[1] ?? null) === 1 && ($error->errorInfo[2] ?? null) === 'cannot start a transaction within a transaction') {
                throw new ReservationFailure('La transacción manual pertenece al llamador; no se adoptó.', 503, $error);
            }
            if ($pdo->inTransaction()) {
                self::$quarantine[] = $pdo;
                $connection->disconnect();
                $connection->setReconnector(static fn () => throw new LogicException('Conexión incierta retirada; reiniciar el worker.'));
                throw new ReservationStorageUncertain($error);
            }
            throw $error;
        }
        $committing = false;
        try {
            $this->admit();
            // Business rejections commit legitimate expirations; structural/auth failures roll back.
            try {
                $result = $operation();
            } catch (ReservationFailure $rejection) {
                $result = $rejection;
            }
            if ($connection->getPdo() !== $pdo || ! $pdo->inTransaction() || $connection->transactionLevel() !== 1) {
                throw new LogicException('Se perdió la propiedad de la transacción.');
            }
            $committing = true;
            $connection->commit();
        } catch (Throwable $error) {
            $secondary = null;
            try {
                if (! $pdo->inTransaction()) {
                    throw new LogicException('No se pudo acreditar el rollback.');
                }
                if ($connection->getPdo() === $pdo && $connection->transactionLevel() > 0) {
                    $connection->rollBack(0);
                } elseif ($pdo->rollBack()) {
                    $connection->setPdo($pdo);
                }
                if ($pdo->inTransaction() || $connection->transactionLevel() !== 0) {
                    throw new LogicException('Rollback incompleto.');
                }
            } catch (Throwable $cleanup) {
                $secondary = $cleanup;
            }
            if ($committing || $secondary !== null) {
                if ($secondary !== null) {
                    self::$quarantine[] = $pdo;
                }
                $connection->disconnect();
                $connection->setReconnector(static fn () => throw new LogicException('Conexión incierta retirada; reiniciar el worker.'));
                throw new ReservationStorageUncertain($error, $secondary);
            }
            throw $error;
        }
        if ($result instanceof ReservationFailure) {
            throw $result;
        }

        return $result;
    }

    public static function writingMysql(): bool
    {
        $connection = DB::connection();

        return $connection instanceof PricingMySqlConnection && $connection->hasReservationTransaction();
    }

    public static function current(Builder|EloquentBuilder $query): Builder|EloquentBuilder
    {
        if (self::writingMysql()) {
            $query->sharedLock();
        }

        return $query;
    }

    public static function activeRows(bool $occupancy = false): Builder
    {
        $now = 'tekora_booking_now_us()';

        return DB::table('service_reservations as current_booking')
            ->leftJoin('reservation_holds as current_hold', 'current_hold.service_reservation_id', '=', 'current_booking.id')
            ->where(function (Builder $state) use ($now, $occupancy) {
                $state->where('current_booking.status', 'confirmed')->orWhere(function (Builder $requested) use ($now, $occupancy) {
                    $requested->where('current_booking.status', 'requested')->where(function (Builder $hold) use ($now, $occupancy) {
                        $hold->where(fn (Builder $held) => $held->where('current_hold.state', 'held')->whereRaw("current_hold.expires_at_us > {$now}"));
                        if (! $occupancy) {
                            $hold->orWhereNull('current_hold.state');
                        }
                    });
                });
            })->sharedLock();
    }

    private function admit(): void
    {
        $connection = DB::connection();
        // SQLite's actual writer lock precedes business reads, authorization and time sampling.
        if ($connection->table('reservation_hold_clock')->where('id', 1)->update(['id' => DB::raw('id')]) !== 1) {
            throw new LogicException('Falta la admisión de reservas.');
        }
        $now = BookingClock::micros();
        if ($connection->table('reservation_holds')->exists()) {
            $watermark = (int) $connection->table('reservation_hold_clock')->where('id', 1)->value('high_water_us');
            if ($now < $watermark) {
                throw new ReservationFailure('El reloj del servidor retrocedió. La operación queda bloqueada hasta recuperar una hora segura.', 503);
            }
            $connection->table('reservation_hold_clock')->where('id', 1)->update(['high_water_us' => $now]);
            $connection->table('reservation_holds')->where('state', 'held')->where('expires_at_us', '<=', $now)->update([
                'state' => 'expired', 'version' => DB::raw('version+1'), 'last_actor_user_id' => null, 'closed_at_us' => DB::raw('expires_at_us')]);
        }
    }

    /** Explicit participation in an Illuminate caller's transaction; owns only a savepoint. */
    private function inSavepoint(callable $operation): mixed
    {
        $connection = DB::connection();
        $parent = $connection->transactionLevel();
        $pdo = $connection->getPdo();
        if ((int) $pdo->query('PRAGMA foreign_keys')->fetchColumn() !== 1) {
            throw new ReservationFailure('FK deshabilitadas.', 503);
        }
        $connection->beginTransaction();
        $ownLevel = $parent + 1;
        $committing = false;
        try {
            $this->admit();
            try {
                $result = $operation();
            } catch (ReservationFailure $rejection) {
                $result = $rejection;
            }
            if ($connection->getPdo() !== $pdo || ! $pdo->inTransaction() || $connection->transactionLevel() !== $ownLevel) {
                throw new LogicException('Se perdió el savepoint de reservas.');
            }
            $committing = true;
            $connection->commit(); // Counter only: NEVER commits the caller's physical transaction.
        } catch (Throwable $error) {
            $secondary = null;
            try {
                if ($connection->getPdo() !== $pdo || ! $pdo->inTransaction()) {
                    throw new LogicException('El llamador conserva una conexión incierta.');
                }
                if ($connection->transactionLevel() === $ownLevel) {
                    $connection->rollBack($parent);
                } elseif ($connection->transactionLevel() === $parent) {
                    $pdo->exec('ROLLBACK TO SAVEPOINT trans'.$ownLevel);
                } else {
                    throw new LogicException('Nivel de savepoint incierto.');
                }
            } catch (Throwable $cleanup) {
                $secondary = $cleanup;
            }
            // Parent connection remains in the caller's custody, including on uncertainty.
            if ($committing || $secondary !== null) {
                throw new ReservationStorageUncertain($error, $secondary);
            }
            throw $error;
        }
        if ($result instanceof ReservationFailure) {
            throw $result;
        }

        return $result;
    }
}
