<?php

namespace App\Services;

use App\Models\ServiceInquiry;
use App\Models\ServiceReservation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

// Ámbito de identidad móvil: las policies compartidas también contemplan prestadores.
final class MobileClientOperations
{
    public function inquiries(User $user): Builder
    {
        return ServiceInquiry::query()->where('service_inquiries.client_user_id', $user->id);
    }

    public function inquiry(User $user, int $id): ServiceInquiry
    {
        $inquiry = $this->inquiries($user)->findOrFail($id);
        Gate::forUser($user)->authorize('view', $inquiry);

        return $inquiry;
    }

    public function reservations(User $user): Builder
    {
        return ServiceReservation::query()->where('service_reservations.client_user_id', $user->id);
    }

    public function reservation(User $user, int $id): ServiceReservation
    {
        $reservation = $this->reservations($user)->findOrFail($id);
        Gate::forUser($user)->authorize('view', $reservation);

        return $reservation;
    }
}
