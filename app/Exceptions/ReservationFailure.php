<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

class ReservationFailure extends RuntimeException implements ShouldntReport
{
    public function __construct(string $message = 'La reserva cambió o hay otra operación en curso. Consulta su estado antes de reintentar.', public readonly int $status = 409)
    {
        parent::__construct($message);
    }
}
