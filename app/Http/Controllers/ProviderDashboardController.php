<?php

namespace App\Http\Controllers;

use App\Exceptions\DashboardFailure;
use App\Services\ProviderDashboardMetrics;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProviderDashboardController extends Controller
{
    public function __invoke(Request $request, ProviderDashboardMetrics $metrics)
    {
        try {
            // Métricas, listados y navegación se renderizan sobre la misma instantánea de lectura.
            return DB::transaction(fn () => response()->view('provider-dashboard.index', [
                'dashboard' => $metrics->build($request->user('web'), $request->query()),
            ]), 3);
        } catch (DashboardFailure $exception) {
            return $exception->render($request);
        } catch (\Illuminate\Auth\Access\AuthorizationException $exception) {
            return (new DashboardFailure('Tu cuenta no tiene acceso al panel del prestador.', 403))->render($request);
        } catch (\Throwable $exception) {
            for ($cause = $exception; $cause; $cause = $cause->getPrevious()) {
                if ($cause instanceof \PDOException) return (new DashboardFailure)->render($request);
            }
            throw $exception;
        }
    }
}
