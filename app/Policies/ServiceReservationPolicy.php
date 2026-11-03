<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\ServiceInquiry;
use App\Models\ServiceReservation;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;

class ServiceReservationPolicy
{
    public function viewAny(Authenticatable $actor): bool
    {
        return $actor instanceof User && ($actor->hasPermission(Permission::ManageOwnInquiries)
            || $actor->hasPermission(Permission::ManageReceivedInquiries));
    }

    public function propose(Authenticatable $actor, ServiceInquiry $inquiry): bool
    {
        return $actor instanceof User && $actor->id === $inquiry->client_user_id
            && $actor->hasPermission(Permission::ManageOwnInquiries)
            && ! $inquiry->service()->whereHas('providerProfile', fn ($q) => $q->where('user_id', $actor->id))->exists();
    }

    public function view(Authenticatable $actor, ServiceReservation $reservation): bool
    {
        return $this->client($actor, $reservation) || $this->confirm($actor, $reservation);
    }

    public function confirm(Authenticatable $actor, ServiceReservation $reservation): bool
    {
        return $actor instanceof User && $actor->hasPermission(Permission::ManageReceivedInquiries)
            && $reservation->providerProfile()->where('user_id', $actor->id)->exists();
    }

    public function reject(Authenticatable $actor, ServiceReservation $reservation): bool { return $this->confirm($actor, $reservation); }
    public function complete(Authenticatable $actor, ServiceReservation $reservation): bool { return $this->confirm($actor, $reservation); }
    public function cancel(Authenticatable $actor, ServiceReservation $reservation): bool { return $this->view($actor, $reservation); }

    private function client(Authenticatable $actor, ServiceReservation $reservation): bool
    {
        return $actor instanceof User && $actor->id === $reservation->client_user_id
            && $actor->hasPermission(Permission::ManageOwnInquiries);
    }
}
