<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

class InquiryMessageUnavailable extends RuntimeException implements ShouldntReport
{
    public function __construct()
    {
        // No adjuntar QueryException: su SQL y bindings pueden contener el cuerpo privado.
        parent::__construct('No se pudo guardar el mensaje. Revisa la conversación antes de volver a intentarlo.');
    }
}
