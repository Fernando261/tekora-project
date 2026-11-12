<?php

declare(strict_types=1);

namespace Tests\Support\PaymentsIntegration;

final readonly class TransactionOutcome
{
    public function __construct(public string $kind, public array $historical, public ?array $current = null, public bool $pendingTimeResolution = false) {}
}
