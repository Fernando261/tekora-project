<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

final class TekoraPageHeaders
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);
        // Anime y la inclinación CSS necesitan estilos inline; ningún script inline o CDN.
        $policy = "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'";
        // En desarrollo Vite puede usar su propio puerto; la política sigue verificándose en producción.
        $header = app()->isLocal() ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy';
        $response->headers->set($header, $policy);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        return $response;
    }
}
