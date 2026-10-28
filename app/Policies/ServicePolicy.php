<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Service;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;

class ServicePolicy
{
    public function viewAny(Authenticatable $actor): bool
    {
        return $actor instanceof User && $actor->hasPermission(Permission::ManageServices);
    }

    public function create(Authenticatable $actor): bool
    {
        return $this->viewAny($actor) && $actor->providerProfile()->exists();
    }

    public function update(Authenticatable $actor, Service $service): bool
    {
        return $this->viewAny($actor) && $service->providerProfile()->where('user_id', $actor->id)->exists();
    }

    public function publish(Authenticatable $actor, Service $service): bool
    {
        return $this->update($actor, $service);
    }

    public function pause(Authenticatable $actor, Service $service): bool
    {
        return $this->update($actor, $service);
    }
}
