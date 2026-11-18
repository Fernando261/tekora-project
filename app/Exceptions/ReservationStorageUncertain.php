<?php

namespace App\Exceptions;

use Throwable;

final class ReservationStorageUncertain extends ReservationFailure
{
    public function __construct(public readonly Throwable $primaryError, public readonly ?Throwable $cleanupError = null)
    {
        parent::__construct('No se pudo comprobar el resultado. Consulta la reserva y reintenta con la misma clave; no se realiza ningún cobro.', 503);
    }
}
