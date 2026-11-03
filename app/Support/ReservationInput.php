<?php

namespace App\Support;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ReservationInput
{
    public static function proposal(array $input): array
    {
        return self::validated($input, [
            'date' => ['required', 'string', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:2100-12-31'],
            'time' => ['required', 'string', 'regex:/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D'],
            'availability_revision' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'idempotency_key' => ['required', 'string', 'uuid'],
        ]);
    }

    public static function decision(array $input): array
    {
        return self::validated($input, ['version' => ['required', 'integer', 'min:1', 'max:2147483647']]);
    }

    private static function validated(array $input, array $rules): array
    {
        $extra = array_diff(array_keys($input), array_keys($rules));
        if ($extra) throw ValidationException::withMessages(['form' => 'El formulario contiene campos no permitidos. Abre de nuevo la reserva.']);

        return Validator::make($input, $rules, [
            'required' => 'Completa este campo.', 'string' => 'Introduce un valor de texto válido.',
            'date_format' => 'Introduce una fecha real con formato AAAA-MM-DD.', 'regex' => 'Introduce una hora válida entre 00:00 y 23:59.',
            'after_or_equal' => 'La fecha no puede ser anterior a 2000-01-01.', 'before_or_equal' => 'La fecha no puede superar 2100-12-31.',
            'integer' => 'El formulario no es válido. Abre de nuevo la reserva.', 'min' => 'El formulario no es válido. Abre de nuevo la reserva.',
            'max' => 'El formulario no es válido. Abre de nuevo la reserva.', 'uuid' => 'El identificador de envío no es válido. Abre de nuevo el formulario.',
        ])->validate();
    }
}
