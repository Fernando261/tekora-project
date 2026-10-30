<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

class InquiryReadFailure extends RuntimeException implements ShouldntReport
{
    public function __construct(public readonly int $status)
    {
        parent::__construct($status === 409 ? 'Hay otra operación en curso. Revisa la conversación e intenta marcar de nuevo.'
            : 'No se pudo actualizar la lectura. Revisa la conversación e inténtalo de nuevo.');
    }
}
