<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\ServiceReservation;
use App\Models\ServiceReview;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;

class ServiceReviewPolicy
{
    public function viewAny(Authenticatable $actor): bool
    {
        return $actor instanceof User && $actor->hasPermission(Permission::ManageOwnInquiries);
    }

    // Roles/propiedad en la policy; estado y unicidad se comprueban transaccionalmente en el servicio.
    public function create(Authenticatable $actor, ServiceReservation $reservation): bool
    {
        return $this->viewAny($actor) && $actor->id === $reservation->client_user_id
            && ! $reservation->providerProfile()->where('user_id', $actor->id)->exists();
    }

    public function view(Authenticatable $actor, ServiceReview $review): bool
    {
        return $this->viewAny($actor) && $actor->id === $review->client_user_id;
    }

    public function withdraw(Authenticatable $actor, ServiceReview $review): bool
    {
        return $this->view($actor, $review);
    }
}
