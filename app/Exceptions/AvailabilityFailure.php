<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

class AvailabilityFailure extends RuntimeException implements ShouldntReport
{
    public function __construct(public readonly int $status = 409)
    {
        parent::__construct($status === 409 ? 'La configuración cambió o hay otra edición en curso. Recarga los horarios antes de guardar de nuevo.'
            : 'No se pudo guardar la disponibilidad. Revisa la configuración e inténtalo de nuevo.');
    }
}
