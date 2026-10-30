<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

class InquiryMessageConflict extends RuntimeException implements ShouldntReport
{
    public function __construct(string $message = 'La solicitud ya no admite mensajes nuevos. Consulta su estado actual.')
    {
        parent::__construct($message);
    }
}
