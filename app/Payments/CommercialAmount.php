<?php

namespace App\Payments;

use InvalidArgumentException;

final readonly class CommercialAmount
{
    // Synthetic technical ceiling, not an approved commercial ticket limit.
    public const MAX_CENTS = 1_000_000_000;

    public const CURRENCY = 'MXN';

    public const DURATION_MINUTES = 60;

    public const POLICY = 'synthetic-commercial-v1-15pct-half-up';

    private function __construct(
        public int $totalCents,
        public int $commissionCents,
        public int $providerCents,
        public string $currency = self::CURRENCY,
        public int $durationMinutes = self::DURATION_MINUTES,
        public string $policyVersion = self::POLICY,
        public int $commissionBasisPoints = 1500,
        public int $providerBasisPoints = 8500,
    ) {}

    public static function fromCents(mixed $value): self
    {
        if (! ((is_int($value) && $value > 0 && $value <= self::MAX_CENTS)
            || (is_string($value) && preg_match('/^[1-9][0-9]{0,9}$/D', $value)
                && (int) $value <= self::MAX_CENTS))) {
            throw new InvalidArgumentException('El precio requiere centavos enteros positivos dentro del límite sintético.');
        }

        $total = (int) $value;
        // Round 15/100 half up using integers; the provider receives the residual.
        $commission = intdiv($total, 100) * 15 + intdiv(($total % 100) * 15 + 50, 100);

        return new self($total, $commission, $total - $commission);
    }
}
