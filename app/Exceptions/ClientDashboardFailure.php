<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\Request;
use RuntimeException;

final class ClientDashboardFailure extends RuntimeException implements ShouldntReport
{
    public function __construct(string $message = 'El panel del cliente no está disponible temporalmente. Inténtalo más tarde.', public readonly int $status = 503)
    {
        parent::__construct($message);
    }

    public function render(Request $request)
    {
        return response()->view('account.client-error', ['message' => $this->getMessage()], $this->status);
    }
}
