<?php

namespace App\Services;

use App\Enums\Permission;
use App\Enums\RoleName;
use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class RoleMemberships
{
    public function enroll(User $user, RoleName $role): void
    {
        $this->assertBusinessRole($role);
        $roleId = Role::where('slug', $role->value)->firstOrFail()->id;

        DB::transaction(function () use ($user, $roleId) {
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $user->roles()->syncWithoutDetaching([$roleId]);
        });
    }

    /** @param list<RoleName> $roles */
    public function replaceBusinessRoles(User $actor, User $target, array $roles): void
    {
        Gate::forUser($actor)->authorize(Permission::ManageAccountRoles->value);

        foreach ($roles as $role) {
            $this->assertBusinessRole($role);
        }

        DB::transaction(function () use ($target, $roles) {
            $target = User::whereKey($target->id)->lockForUpdate()->firstOrFail();
            $businessIds = Role::whereIn('slug', RoleName::businessValues())->pluck('id');
            $requestedIds = Role::whereIn('slug', array_map(fn (RoleName $role) => $role->value, $roles))->pluck('id');

            // Esta operación nunca agrega ni elimina el rol administrativo.
            $target->roles()->detach($businessIds->diff($requestedIds)->all());
            $target->roles()->syncWithoutDetaching($requestedIds->all());
        });
    }

    private function assertBusinessRole(RoleName $role): void
    {
        if ($role === RoleName::Admin) {
            throw new AuthorizationException('La asignación administrativa requiere un procedimiento separado.');
        }
    }
}
