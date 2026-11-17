<?php

namespace App\Payments;

use RuntimeException;
use Throwable;

final class PricingUnavailable extends RuntimeException
{
    public function __construct(Throwable $primary, public readonly ?Throwable $cleanupError = null)
    {
        parent::__construct('No se pudo confirmar el resultado. Consulta el historial y reintenta con la misma clave; no se efectuó ningún cobro.', previous: $primary);
    }
}
