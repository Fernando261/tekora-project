<?php

namespace App\Policies;

use App\Models\ActivityNotification;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;

class ActivityNotificationPolicy
{
    public function read(Authenticatable $actor, ActivityNotification $notification): bool
    {
        return $actor instanceof User && $actor->id === $notification->user_id;
    }
}
