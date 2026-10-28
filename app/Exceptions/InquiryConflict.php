<?php

namespace App\Exceptions;

use RuntimeException;

class InquiryConflict extends RuntimeException
{
    public function __construct(public readonly ?int $inquiryId, string $message = 'La solicitud ya cambió de estado. Consulta su estado actual antes de continuar.')
    {
        parent::__construct($message);
    }
}
