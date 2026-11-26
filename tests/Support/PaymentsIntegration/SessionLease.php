<?php

declare(strict_types=1);

namespace Tests\Support\PaymentsIntegration;

/** Object identity in the owner's WeakMap is the authority, never these diagnostics. */
final class SessionLease
{
    public function __construct(public readonly int $pid, public readonly int $epoch) {}

    public function assertProcess(): void
    {
        if ($this->pid !== getmypid()) {
            throw new \LogicException('Lease belongs to another process');
        }
    }

    private function __clone() {}

    public function __serialize(): array
    {
        throw new \LogicException('Sessions cannot be transferred');
    }
}
