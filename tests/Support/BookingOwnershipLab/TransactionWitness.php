<?php

declare(strict_types=1);

namespace Tests\Support\BookingOwnershipLab;

use PDO;
use WeakReference;

final class TransactionWitness
{
    private readonly WeakReference $pdo;

    private readonly int $pid;

    private readonly string $nonce;

    private bool $revoked = false;

    private function __construct(PDO $pdo, public readonly string $connectionEpoch, public readonly int $generation)
    {
        $this->pdo = WeakReference::create($pdo);
        $this->pid = getmypid();
        $this->nonce = bin2hex(random_bytes(16));
    }

    /** Internal native-return boundary only; never reconstructed from a counter. */
    public static function nativeReturned(PDO $pdo, string $epoch, int $generation): self
    {
        return new self($pdo, $epoch, $generation);
    }

    public function matches(PDO $pdo, string $epoch, int $generation): bool
    {
        return ! $this->revoked && $this->pdo->get() === $pdo && $this->pid === getmypid()
            && $this->connectionEpoch === $epoch && $this->generation === $generation;
    }

    public function nonce(): string
    {
        return $this->nonce;
    }

    public function revoke(): void
    {
        $this->revoked = true;
    }

    public function __clone(): void
    {
        throw new LabFailure('WitnessMissing', 'CLONE');
    }

    public function __serialize(): array
    {
        throw new LabFailure('WitnessMissing', 'SERIALIZE');
    }
}
