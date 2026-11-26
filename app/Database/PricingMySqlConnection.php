<?php

declare(strict_types=1);

namespace App\Database;

use App\Database\Provisioning\MySqlProvisioner;
use App\Exceptions\ReservationStorageUncertain;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\MySqlConnection;
use LogicException;
use PDO;
use Throwable;

/** Marks the owned unit for Laravel's queries, so neither readers nor reconnect retries can escape it. */
final class PricingMySqlConnection extends MySqlConnection
{
    private ?PDO $pricingOwner = null;

    private bool $pricingInvalid = false;

    private ?PDO $reservationOwner = null;

    private ?Throwable $callerRollbackCause = null;

    private ?PDO $callerRollbackPdo = null;

    private bool $provisioning = false;

    public function usingProvisioningDefiner(Closure $operation): mixed
    {
        if ($this->getName() !== MySqlProvisioner::CONNECTION || $this->provisioning || $this->transactionLevel() !== 0) {
            throw new LogicException('El DEFINER pertenece a la sesión de aprovisionamiento.');
        }
        $this->provisioning = true;
        try {
            return $operation();
        } finally {
            $this->provisioning = false;
        }
    }

    public function unprepared($query)
    {
        if ($this->provisioning) {
            // The original migration bodies and normal query paths remain intact.
            $query = preg_replace('/^\s*CREATE\s+(FUNCTION|PROCEDURE|TRIGGER|VIEW)\s+/i',
                'CREATE DEFINER=`tekora_definer`@`localhost` $1 ', $query);
        }

        return parent::unprepared($query);
    }

    public function hasReservationTransaction(): bool
    {
        return $this->reservationOwner !== null;
    }

    public function beginReservationWork(PDO $pdo, int $parent, string $savepoint): void
    {
        $this->assertOutsidePricing();
        if ($this->transactions !== $parent || $this->getRawPdo() !== $pdo || $pdo->inTransaction() !== ($parent > 0)) {
            throw new LogicException('No se acreditó la propiedad de la transacción de reservas.');
        }
        if ($parent > 0) {
            if (! preg_match('/^tekora_b2_[0-9a-f]{24}$/D', $savepoint) || $pdo->exec('SAVEPOINT '.$savepoint) === false) {
                throw new LogicException('SAVEPOINT no acreditado.');
            }
        } elseif (! $pdo->beginTransaction() || ! $pdo->inTransaction()) {
            throw new LogicException('BEGIN no acreditó la transacción de reservas.');
        }
        $this->reservationOwner = $pdo;
        $this->transactions = $parent + 1;
    }

    public function ownsReservationWork(PDO $pdo, int $parent): bool
    {
        return $this->reservationOwner === $pdo && $this->getRawPdo() === $pdo && $this->transactions === $parent + 1;
    }

    public function finishReservationWork(int $parent): void
    {
        $this->reservationOwner = null;
        $this->transactions = $parent;
    }

    public function requireCallerRollback(int $parent, PDO $pdo, Throwable $cause): void
    {
        $this->finishReservationWork($parent);
        $this->callerRollbackPdo = $pdo;
        $this->callerRollbackCause = $cause;
    }

    public function hasPricingTransaction(): bool
    {
        return $this->pricingOwner !== null;
    }

    /** Read the current row and protect it through COMMIT, during a commercial write. */
    public static function lockPricingRead(Builder $query): Builder
    {
        $connection = $query->getConnection();
        if ($connection instanceof self && ($connection->hasPricingTransaction() || $connection->hasReservationTransaction())) {
            $query->sharedLock();
        }

        return $query;
    }

    public function beginPricing(PDO $pdo): void
    {
        $this->assertOutsidePricing();
        if ($this->transactions !== 0 || $this->getRawPdo() !== $pdo || $pdo->inTransaction()) {
            throw new LogicException('La transacción existente pertenece al llamador.');
        }

        if (! $pdo->beginTransaction() || ! $pdo->inTransaction()) {
            throw new LogicException('BEGIN no acreditó una transacción propia.');
        }

        $this->pricingOwner = $pdo;
        $this->transactions = 1;
    }

    public function ownsPricing(PDO $pdo): bool
    {
        return $this->pricingOwner === $pdo && $this->transactions === 1 && $this->getRawPdo() === $pdo;
    }

    public function releasePricing(): void
    {
        $this->pricingOwner = null;
        $this->transactions = 0;
    }

    public function retirePricing(): void
    {
        $this->pricingInvalid = true;
        $this->releasePricing();
        $this->disconnect();
        $this->setReconnector(static fn () => throw new LogicException('Conexión de precios invalidada. Retira el worker.'));
    }

    public function transaction(Closure $callback, $attempts = 1)
    {
        $this->assertOutsidePricing();
        $parent = $this->transactions;
        try {
            return parent::transaction(function ($connection) use ($callback, $parent) {
                $pdo = $this->getPdo();
                $result = $callback($connection);
                $this->assertCallerCanCommit();
                if ($this->transactions !== $parent + 1 || $this->getRawPdo() !== $pdo || ! $pdo->inTransaction()) {
                    throw new LogicException('La unidad Laravel perdió su transacción física o su nivel.');
                }

                return $result;
            }, $attempts);
        } catch (Throwable $error) {
            if ($this->callerRollbackCause !== null) {
                if ($parent === 0) {
                    // This invocation opened the root. Do not report success or retry it.
                    try {
                        $this->rollBack(0);
                    } catch (Throwable $cleanup) {
                        throw new ReservationStorageUncertain($error, $cleanup);
                    }
                } else {
                    // Leave the physical transaction in its owner's custody. Only unwind Laravel's frame.
                    $this->transactions = $parent;
                }
            }

            throw $error;
        }
    }

    protected function handleTransactionException(Throwable $e, $currentAttempt, $maxAttempts)
    {
        if ($this->callerRollbackCause !== null) {
            throw $e;
        }

        parent::handleTransactionException($e, $currentAttempt, $maxAttempts);
    }

    protected function handleCommitTransactionException(Throwable $e, $currentAttempt, $maxAttempts)
    {
        if ($this->callerRollbackCause !== null) {
            throw $e;
        }

        parent::handleCommitTransactionException($e, $currentAttempt, $maxAttempts);
    }

    public function getPdo()
    {
        // Laravel's transaction() commits directly on PDO, after firing the committing event.
        $this->assertCallerCanCommit();

        return parent::getPdo();
    }

    public function setPdo($pdo)
    {
        $this->assertCallerCanCommit();

        return parent::setPdo($pdo);
    }

    public function afterCommit($callback)
    {
        // The direct-PDO scopes do not own a Laravel transaction-manager record.
        $this->assertOutsidePricing();

        return parent::afterCommit($callback);
    }

    public function beginTransaction()
    {
        $this->assertOutsidePricing();
        parent::beginTransaction();
    }

    public function commit()
    {
        $this->assertCallerCanCommit();
        $this->assertOutsidePricing();
        parent::commit();
    }

    public function rollBack($toLevel = null)
    {
        if ($this->callerRollbackCause !== null) {
            if (($toLevel ?? $this->transactions - 1) !== 0) {
                throw new LogicException('El llamador debe revertir la transacción completa.');
            }
            $pdo = $this->getRawPdo();
            if (! $pdo instanceof PDO || $pdo !== $this->callerRollbackPdo || $this->transactions < 1) {
                throw new LogicException('No se acreditó la sesión original del llamador.');
            }
            // Refresh the server status on the same session before trusting an inactive PDO.
            $status = $pdo->query('SELECT 1');
            if ($status === false || ! $status->closeCursor() || ($pdo->inTransaction() && (! $pdo->rollBack() || $pdo->inTransaction()))) {
                throw new LogicException('El llamador conserva una transacción incierta.');
            }
            // Keep the guard until Laravel has also discarded the abandoned callbacks.
            $this->transactionsManager?->rollback($this->getName(), 0);
            $this->finishReservationWork(0);
            $this->callerRollbackCause = null;
            $this->callerRollbackPdo = null;
            $this->fireConnectionEvent('rollingBack');

            return;
        }
        $this->assertOutsidePricing();
        parent::rollBack($toLevel);
    }

    protected function run($query, $bindings, Closure $callback)
    {
        if ($this->pricingInvalid) {
            throw new LogicException('Conexión de precios invalidada. Retira el worker.');
        }
        if ($this->callerRollbackCause !== null) {
            throw new LogicException('El llamador debe revertir la transacción completa.');
        }
        $pdo = $this->getRawPdo();
        if ($this->reservationOwner !== null && ($pdo !== $this->reservationOwner || ! $pdo->inTransaction())) {
            throw new LogicException('La transacción de reservas ya no está activa.');
        }
        if ($this->pricingOwner !== null && (! $pdo instanceof PDO || ! $this->ownsPricing($pdo) || ! $pdo->inTransaction())) {
            throw new LogicException('La transacción de precios ya no está activa.');
        }

        return parent::run($query, $bindings, $callback);
    }

    private function assertCallerCanCommit(): void
    {
        if ($this->callerRollbackCause !== null) {
            throw $this->callerRollbackCause;
        }
    }

    private function assertOutsidePricing(): void
    {
        $this->assertCallerCanCommit();
        if ($this->pricingInvalid || $this->pricingOwner !== null || $this->reservationOwner !== null) {
            throw new LogicException('La conexión pertenece a un adaptador comercial o fue retirada.');
        }
    }
}
