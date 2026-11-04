<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\Request;
use RuntimeException;

class ReviewFailure extends RuntimeException implements ShouldntReport
{
    public function __construct(string $message = 'Hay otra operación en curso. Consulta Mis reseñas antes de volver a enviar.', public readonly int $status = 409)
    {
        parent::__construct($message);
    }

    public function render(Request $request)
    {
        $message = $this->getMessage();
        if ($request->expectsJson()) return response()->json(['message' => $message], $this->status);

        return response()->view('reviews.error', compact('message'), $this->status);
    }
}
