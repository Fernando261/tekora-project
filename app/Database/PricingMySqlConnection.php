<?php

declare(strict_types=1);

namespace App\Database;

use Closure;
use Illuminate\Database\MySqlConnection;
use LogicException;
use PDO;

/** Marks the owned unit for Laravel's queries, so neither readers nor reconnect retries can escape it. */
final class PricingMySqlConnection extends MySqlConnection
{
    private ?PDO $pricingOwner = null;

    private bool $pricingInvalid = false;

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
        $this->assertOutsidePricing();
        parent::rollBack($toLevel);
    }

    protected function run($query, $bindings, Closure $callback)
    {
        if ($this->pricingInvalid) {
            throw new LogicException('Conexión de precios invalidada. Retira el worker.');
        }
        $pdo = $this->getRawPdo();
        if ($this->pricingOwner !== null && (! $pdo instanceof PDO || ! $this->ownsPricing($pdo) || ! $pdo->inTransaction())) {
            throw new LogicException('La transacción de precios ya no está activa.');
        }

        return parent::run($query, $bindings, $callback);
    }

    private function assertOutsidePricing(): void
    {
        if ($this->pricingInvalid || $this->pricingOwner !== null) {
            throw new LogicException('La transacción pertenece al adaptador de precios.');
        }
    }
}
