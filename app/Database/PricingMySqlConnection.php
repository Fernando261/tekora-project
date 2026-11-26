<?php

declare(strict_types=1);

namespace App\Database;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\MySqlConnection;
use LogicException;
use PDO;

/** Marks the owned unit for Laravel's queries, so neither readers nor reconnect retries can escape it. */
final class PricingMySqlConnection extends MySqlConnection
{
    private ?PDO $pricingOwner = null;

    private bool $pricingInvalid = false;

    private ?PDO $reservationOwner = null;

    private bool $callerMustRollback = false;

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

    public function finishReservationWork(int $parent, bool $mustRollback = false): void
    {
        $this->reservationOwner = null;
        $this->transactions = $parent;
        $this->callerMustRollback = $mustRollback;
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

    public function beginTransaction()
    {
        $this->assertOutsidePricing();
        parent::beginTransaction();
    }

    public function commit()
    {
        $this->assertOutsidePricing();
        parent::commit();
    }

    public function rollBack($toLevel = null)
    {
        if ($this->callerMustRollback) {
            if (($toLevel ?? $this->transactions - 1) !== 0) {
                throw new LogicException('El llamador debe revertir la transacción completa.');
            }
            $pdo = $this->getRawPdo();
            if (! $pdo instanceof PDO || ($pdo->inTransaction() && (! $pdo->rollBack() || $pdo->inTransaction()))) {
                throw new LogicException('El llamador conserva una transacción incierta.');
            }
            $this->finishReservationWork(0);
            $this->transactionsManager?->rollback($this->getName(), 0);
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
        if ($this->callerMustRollback) {
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

    private function assertOutsidePricing(): void
    {
        if ($this->pricingInvalid || $this->pricingOwner !== null || $this->reservationOwner !== null || $this->callerMustRollback) {
            throw new LogicException('La transacción pertenece al adaptador de precios.');
        }
    }
}
