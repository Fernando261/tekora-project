<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\ProviderProfile;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;

class ProviderProfilePolicy
{
    public function create(Authenticatable $actor): bool
    {
        return $actor instanceof User && $actor->hasPermission(Permission::ManageProviderProfile);
    }

    public function view(Authenticatable $actor, ProviderProfile $profile): bool
    {
        return $actor instanceof User && (
            $actor->hasPermission(Permission::ReviewProviderProfiles)
            || $this->update($actor, $profile)
        );
    }

    public function update(Authenticatable $actor, ProviderProfile $profile): bool
    {
        return $actor instanceof User
            && $actor->id === $profile->user_id
            && $actor->hasPermission(Permission::ManageProviderProfile);
    }
}
