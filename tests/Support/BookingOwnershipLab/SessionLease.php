<?php

declare(strict_types=1);

namespace Tests\Support\BookingOwnershipLab;

use Closure;

final class SessionLease
{
    private bool $active = true;

    private bool $poisoned = false;

    private bool $sealed = false;

    public function __construct(private ?Closure $operation) {}

    public function write(ProbeOperation $operation, int $value = 0): void
    {
        if (! $this->active || $this->operation === null) {
            throw new LabFailure($this->poisoned ? 'SessionPoisoned' : 'SessionRevoked', 'LEASE');
        }
        // A post-seal attempt still reaches the coordinator's poison boundary.
        ($this->operation)($operation, $value);
        if ($operation === ProbeOperation::RECEIPT) {
            $this->sealed = true;
        }
    }

    public function permitsWrite(): bool
    {
        return $this->active && ! $this->poisoned && ! $this->sealed;
    }

    public function poison(): void
    {
        $this->poisoned = true;
        $this->revoke();
    }

    public function revoke(): void
    {
        $this->active = false;
        $this->operation = null; // A retained participant cannot keep PDO alive.
    }

    public function __clone(): void
    {
        throw new LabFailure('SessionRevoked', 'CLONE');
    }

    public function __serialize(): array
    {
        throw new LabFailure('SessionRevoked', 'SERIALIZE');
    }
}
