<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(append: [\App\Http\Middleware\ApiHeaders::class]);
        $middleware->redirectGuestsTo(function (Request $request) {
            $professionalRoute = in_array('auth:profesionista', $request->route()->gatherMiddleware(), true);

            return route('login', $professionalRoute ? ['role' => 'prof'] : []);
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->report(function (\PDOException $exception) {
            if (request()->is('api/v1', 'api/v1/*')) {
                // Un fallo de INSERT puede llevar cuerpos privados en sus bindings.
                \Illuminate\Support\Facades\Log::warning('Fallo de almacenamiento en API móvil.', ['exception' => $exception::class]);

                return false;
            }
        });
        $exceptions->shouldRenderJsonWhen(fn (Request $request, \Throwable $exception) =>
            $request->is('api/v1', 'api/v1/*') || $request->expectsJson());
        $exceptions->render(function (\Throwable $exception, Request $request) {
            if ($request->is('api/v1', 'api/v1/*')) return \App\Support\Api\ApiError::from($exception);
        });
        $exceptions->respond(function ($response) {
            if (request()->is('api/v1', 'api/v1/*')) {
                $response->headers->set('Cache-Control', 'no-store, private');
                $response->headers->set('X-Content-Type-Options', 'nosniff');
            }

            return $response;
        });
    })->create();
