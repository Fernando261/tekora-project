<?php

namespace App\Payments;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class PricingInput
{
    public static function only(array $input, array $allowed = []): void
    {
        if (array_diff(array_keys($input), $allowed)) {
            throw ValidationException::withMessages(['form' => 'La operación contiene campos no permitidos.']);
        }
    }

    public static function key(array $input): string
    {
        $key = $input['idempotency_key'] ?? null;
        if (! is_string($key) || ! Str::isUuid($key)) {
            throw ValidationException::withMessages(['idempotency_key' => 'Usa un UUID para la intención de envío.']);
        }

        return strtolower($key);
    }

    public static function amount(array $input): CommercialAmount
    {
        try {
            return CommercialAmount::fromCents($input['amount_cents'] ?? null);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['amount_cents' => $exception->getMessage()]);
        }
    }

    public static function version(array $input): int
    {
        $value = $input['expected_version'] ?? null;
        if (! ((is_int($value) || is_string($value)) && preg_match('/^(0|[1-9][0-9]{0,8})$/D', (string) $value))) {
            throw ValidationException::withMessages(['expected_version' => 'Indica una versión entera canónica.']);
        }

        return (int) $value;
    }
}
