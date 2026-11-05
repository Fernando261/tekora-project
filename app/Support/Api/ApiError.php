<?php

namespace App\Support\Api;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class ApiError
{
    public static function response(int $status, string $code, string $message, array $fields = [], array $headers = []): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message, 'fields' => (object) $fields]], $status, $headers);
    }

    public static function from(Throwable $exception): JsonResponse
    {
        if ($exception instanceof ValidationException) {
            return self::response(422, 'validation_failed', 'Los datos enviados no son válidos.', $exception->errors());
        }
        $status = match (true) {
            $exception instanceof AuthenticationException => 401,
            $exception instanceof AuthorizationException => 403,
            $exception instanceof HttpExceptionInterface => $exception->getStatusCode(),
            $exception instanceof \PDOException => 503,
            default => 500,
        };
        [$code, $message] = match ($status) {
            400 => ['bad_request', 'La petición no es válida.'],
            401 => ['unauthenticated', 'Se requiere un token válido.'],
            403 => ['forbidden', 'No tienes permiso para esta operación.'],
            404 => ['not_found', 'El recurso no está disponible.'],
            405 => ['method_not_allowed', 'El método no está permitido.'],
            409 => ['conflict', 'La operación entra en conflicto con el estado actual.'],
            422 => ['validation_failed', 'Los datos enviados no son válidos.'],
            429 => ['rate_limited', 'Demasiados intentos. Inténtalo más tarde.'],
            503 => ['temporarily_unavailable', 'El servicio no está disponible temporalmente.'],
            default => ['server_error', 'No se pudo completar la petición.'],
        };
        $headers = $exception instanceof HttpExceptionInterface
            ? array_intersect_key($exception->getHeaders(), array_flip(['Retry-After', 'X-RateLimit-Limit', 'X-RateLimit-Remaining', 'Allow'])) : [];

        return self::response($status, $code, $message, [], $headers);
    }
}
