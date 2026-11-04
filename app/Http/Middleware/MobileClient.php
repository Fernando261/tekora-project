<?php

namespace App\Http\Middleware;

use App\Enums\Permission;
use App\Enums\RoleName;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

final class MobileClient
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user('sanctum');
        $token = $user?->currentAccessToken();
        abort_unless($request->bearerToken() && $user instanceof User && $token instanceof PersonalAccessToken, 401);
        // Comprobar pertenencias actuales; ni abilities ni relaciones cacheadas otorgan el rol.
        abort_unless(str_starts_with($token->name, 'mobile:') && $token->can('client:read')
            && $user->hasRole(RoleName::Client)
            && $user->hasPermission(Permission::ViewClientAppointments)
            && $user->hasPermission(Permission::ManageOwnInquiries), 403);

        return $next($request);
    }
}
