<?php

namespace App\Payments;

use Illuminate\Validation\ValidationException;

final class WebMoney
{
    public static function cents(mixed $value): int
    {
        if (! is_string($value) || ! preg_match('/^(0|[1-9][0-9]{0,7})(?:\.([0-9]{1,2}))?$/D', $value, $matches)) {
            throw ValidationException::withMessages(['price_mxn' => 'Escribe un precio MXN positivo, sin separadores, con hasta dos decimales (ejemplo: 1000.00).']);
        }
        if ((int) $matches[1] > intdiv(CommercialAmount::MAX_CENTS, 100)) {
            throw ValidationException::withMessages(['price_mxn' => 'El importe excede el límite técnico de $10,000,000.00 MXN.']);
        }
        $cents = ((int) $matches[1]) * 100 + (int) str_pad($matches[2] ?? '', 2, '0');
        if ($cents < 1 || $cents > CommercialAmount::MAX_CENTS) {
            throw ValidationException::withMessages(['price_mxn' => 'El precio debe ser entre $0.01 y $10,000,000.00 MXN (límite técnico).']);
        }

        return $cents;
    }

    public static function decimal(int $cents): string
    {
        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function format(int $cents): string
    {
        return '$'.preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', (string) intdiv($cents, 100)).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT).' MXN';
    }
}
