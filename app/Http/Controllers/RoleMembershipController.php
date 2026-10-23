<?php

namespace App\Http\Controllers;

use App\Enums\RoleName;
use App\Http\Requests\EnrollRoleRequest;
use App\Http\Requests\UpdateBusinessRolesRequest;
use App\Models\Role;
use App\Models\User;
use App\Services\RoleMemberships;
use Illuminate\Http\JsonResponse;

class RoleMembershipController extends Controller
{
    public function store(EnrollRoleRequest $request, RoleMemberships $memberships): JsonResponse
    {
        $user = $request->user('web');
        $memberships->enroll($user, RoleName::from($request->validated('role')));

        return response()->json(['roles' => $user->roles()->orderBy('slug')->pluck('slug')]);
    }

    public function index(): JsonResponse
    {
        return response()->json(['roles' => Role::orderBy('slug')->get(['slug', 'name'])]);
    }

    public function update(UpdateBusinessRolesRequest $request, User $user, RoleMemberships $memberships): JsonResponse
    {
        $roles = array_map(fn (string $role) => RoleName::from($role), $request->validated('roles'));
        $memberships->replaceBusinessRoles($request->user('web'), $user, $roles);

        return response()->json(['roles' => $user->roles()->orderBy('slug')->pluck('slug')]);
    }
}
