<?php

namespace App\Http\Controllers;

use App\Enums\RoleName;
use App\Http\Requests\EnrollRoleRequest;
use App\Http\Requests\UpdateBusinessRolesRequest;
use App\Models\Role;
use App\Models\User;
use App\Services\RoleMemberships;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoleMembershipController extends Controller
{
    public function store(EnrollRoleRequest $request, RoleMemberships $memberships)
    {
        $user = $request->user('web');
        $memberships->enroll($user, RoleName::from($request->validated('role')));

        if ($request->expectsJson()) {
            return response()->json(['roles' => $user->roles()->orderBy('slug')->pluck('slug')]);
        }

        $provider = $request->validated('role') === RoleName::Provider->value;

        return redirect()->route($provider ? 'account.provider' : 'account.client', [], 303)
            ->with('success', $provider ? 'Ya puedes ofrecer servicios con tu misma cuenta. Completa tu perfil profesional.'
                : 'La experiencia de cliente está activada en tu cuenta.');
    }

    public function index(Request $request)
    {
        $roles = Role::orderBy('slug')->get(['slug', 'name']);

        return $request->expectsJson() ? response()->json(['roles' => $roles]) : view('account.roles', compact('roles'));
    }

    public function update(UpdateBusinessRolesRequest $request, User $user, RoleMemberships $memberships): JsonResponse
    {
        $roles = array_map(fn (string $role) => RoleName::from($role), $request->validated('roles'));
        $memberships->replaceBusinessRoles($request->user('web'), $user, $roles);

        return response()->json(['roles' => $user->roles()->orderBy('slug')->pluck('slug')]);
    }
}
