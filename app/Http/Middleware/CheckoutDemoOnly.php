<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

final class CheckoutDemoOnly
{
    /** Same local boundary for the demo routes and their optional web invitation. */
    public static function permitsLocalRequest(Request $request): bool
    {
        return app()->environment(['local', 'testing']) && ! app()->configurationIsCached()
            && ! is_file(app()->getCachedConfigPath())
            && in_array($request->ip(), ['127.0.0.1', '::1'], true)
            && in_array(strtolower($request->getHost()), ['localhost', '127.0.0.1', '[::1]', '::1'], true);
    }

    public function handle(Request $request, Closure $next)
    {
        if (! self::permitsLocalRequest($request)) {
            $response = response()->json(['error' => ['code' => 'not_found', 'message' => 'No disponible.']], 404);
        } else {
            try {
                $response = $next($request);
            } catch (\Throwable $error) {
                $status = $error instanceof \Illuminate\Validation\ValidationException ? 422
                    : ($error instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface ? $error->getStatusCode() : 503);
                $code = str_starts_with($error->getMessage(), 'demo_') ? $error->getMessage() : 'demo_unavailable';
                $response = response()->json(['error' => ['code' => $code, 'message' => 'La operación demo no pudo completarse. Consulta el resultado antes de reintentar.']], $status);
            }
        }

        // Laravel's routing pipeline can render an exception before it returns here.
        // Keep those failures explicit and redact debug output at the demo boundary too.
        if ($response->getStatusCode() >= 400) {
            $status = $response->getStatusCode() >= 500 ? 503 : $response->getStatusCode();
            $data = json_decode($response->getContent(), true);
            $candidate = $data['error']['code'] ?? $data['message'] ?? '';
            $code = is_string($candidate) && str_starts_with($candidate, 'demo_') ? $candidate : 'demo_unavailable';
            $response = response()->json(['error' => ['code' => $code, 'message' => 'La operación demo no pudo completarse. Consulta el resultado antes de reintentar.']], $status);
        }

        return $response->withHeaders(['Cache-Control' => 'no-store, private', 'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff', 'X-Frame-Options' => 'DENY', 'Permissions-Policy' => 'camera=(), microphone=(), payment=()',
            'Content-Security-Policy' => "default-src 'none'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; form-action 'none'; base-uri 'none'; object-src 'none'; frame-ancestors 'none'"]);
    }
}
