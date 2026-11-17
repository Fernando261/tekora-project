<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

class ReservationFailure extends RuntimeException implements ShouldntReport
{
    public function __construct(string $message = 'La reserva cambió o hay otra operación en curso. Consulta su estado antes de reintentar.', public readonly int $status = 409, public readonly ?\Throwable $storageError = null)
    {
        parent::__construct($message);
    }

    public function render(\Illuminate\Http\Request $request)
    {
        if ($request->is('api/v1', 'api/v1/*')) {
            return \App\Support\Api\ApiError::from($this);
        }
        if ($request->expectsJson()) {
            return response()->json(['message' => $this->getMessage()], $this->status);
        }

        return response()->view('reservations.error', ['message' => $this->getMessage()], $this->status);
    }
}
