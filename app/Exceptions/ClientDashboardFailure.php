<?php

namespace App\Exceptions;

use App\Support\Api\ApiError;
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
        if ($request->is('api/v1', 'api/v1/*')) {
            return ApiError::from($this);
        }

        return response()->view('account.client-error', ['message' => $this->getMessage()], $this->status);
    }
}
