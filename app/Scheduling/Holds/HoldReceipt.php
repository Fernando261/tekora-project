<?php

declare(strict_types=1);

namespace App\Scheduling\Holds;

final readonly class HoldReceipt
{
    public function __construct(public string $scopeKey, public string $fingerprint, public string $holdId) {}
}
