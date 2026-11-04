<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\Request;
use RuntimeException;

class DashboardFailure extends RuntimeException implements ShouldntReport
{
    public function __construct(string $message = 'El panel no está disponible temporalmente. Inténtalo más tarde.', public readonly int $status = 503)
    {
        parent::__construct($message);
    }

    public function render(Request $request)
    {
        $message = $this->getMessage();

        return response()->view('provider-dashboard.error', compact('message'), $this->status);
    }
}
