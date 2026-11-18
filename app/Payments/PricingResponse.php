<?php

namespace App\Payments;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use LogicException;
use PDOException;

final class PricingResponse
{
    public static function run(Request $request, callable $operation)
    {
        try {
            return $operation();
        } catch (PricingConflict $error) {
            return self::error($request, $error->getMessage(), 409);
        } catch (PricingUnavailable|PDOException|LogicException $error) {
            Log::warning('Almacenamiento de precios no disponible.', ['type' => $error::class]);

            return self::error($request, 'No se pudo comprobar el resultado. Consulta el historial y reintenta con la misma clave. No se realizan cobros.', 503);
        }
    }

    private static function error(Request $request, string $message, int $status)
    {
        if ($request->is('api/v1', 'api/v1/*') || $request->routeIs('reservations.checkout-context')) {
            return \App\Support\Api\ApiError::response($status,
                $status === 409 ? 'conflict' : 'temporarily_unavailable',
                $status === 409 ? 'La operación entra en conflicto con el estado actual.' : 'No se pudo comprobar el resultado. Consulta el historial y reintenta con la misma clave.');
        }
        if ($request->expectsJson()) {
            return response()->json(['message' => $message], $status);
        }

        return response()->view('quotes.error', compact('message'), $status);
    }
}
