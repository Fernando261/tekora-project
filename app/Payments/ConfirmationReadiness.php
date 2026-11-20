<?php

namespace App\Payments;

use App\Http\Controllers\LocalIntegratedCheckoutController;
use App\Models\ServiceReservation;
use App\Support\BookingClock;

/** Advisory projection of CheckoutContext and the actual B3.2 commit predicate. */
final class ConfirmationReadiness
{
    public function forReservation(ServiceReservation $reservation, array $blockers, int $observedAt): array
    {
        $status = $reservation->effectiveStatus($observedAt)->value;
        $state = match ($status) {
            'confirmed' => 'already_confirmed',
            'cancelled', 'completed', 'rejected', 'hold_expired', 'hold_released' => $status,
            default => ! $reservation->quote_id ? 'not_applicable'
                : ($blockers === [] ? 'ready' : ($blockers === ['hold_not_started'] ? 'awaiting_provider' : 'blocked')),
        };
        $requirement = 'not_applicable';
        // Writer admission rejects a rolled-back server clock. The existing
        // context still reports the high-water time, but must not offer commit.
        if ($state === 'ready' && BookingClock::micros() < $observedAt) {
            $state = 'unknown';
            $blockers[] = 'server_clock_uncertain';
        } elseif ($state === 'ready' && LocalIntegratedCheckoutController::permitsSyntheticRequest(request())) {
            // Same predicate used by ReservationWorkflow, never a second payment policy.
            $satisfied = app(LocalCheckoutAttemptStore::class)->permitsCommit($reservation);
            $requirement = $satisfied ? 'satisfied' : 'required';
            if (! $satisfied) {
                $state = 'web_checkout_required';
                $blockers[] = 'synthetic_web_checkout_required';
            }
        }

        return ['state' => $state, 'can_offer_mobile_confirmation' => $state === 'ready',
            'advisory_only' => true, 'reservation_id' => $reservation->id,
            'reservation_version' => $reservation->version,
            'synthetic_web_requirement' => $requirement, 'blocking_reasons' => $blockers,
            'observed_at' => BookingClock::date($observedAt)->toISOString()];
    }
}
