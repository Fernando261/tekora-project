<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\Request;
use RuntimeException;

class NotificationFailure extends RuntimeException implements ShouldntReport
{
    public function __construct(string $message = 'Las notificaciones no están disponibles temporalmente. Inténtalo más tarde.', public readonly int $status = 503)
    {
        parent::__construct($message);
    }

    public function render(Request $request)
    {
        $message = $this->getMessage();
        if ($request->expectsJson()) return response()->json(['message' => $message], $this->status);

        return response()->view('notifications.error', compact('message'), $this->status);
    }
}
