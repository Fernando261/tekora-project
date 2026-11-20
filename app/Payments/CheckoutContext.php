<?php

namespace App\Payments;

use App\Enums\InquiryStatus;
use App\Enums\Permission;
use App\Enums\ReservationStatus;
use App\Models\ServiceReservation;
use App\Models\User;
use App\Support\BookingClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use LogicException;

/** Advisory read model shared by web/mobile. No payment or booking command. */
final class CheckoutContext
{
    public function __construct(private readonly PricingStore $store, private readonly QuoteReadModel $quotes) {}

    public function forReservation(User $actor, int $id, ?int $observedAt = null): array
    {
        return DB::transaction(function () use ($actor, $id, $observedAt) {
            $reservation = ServiceReservation::where('client_user_id', $actor->id)->findOrFail($id);
            Gate::forUser($actor)->authorize('commit', $reservation);
            $reservation->load(['hold', 'client', 'inquiry', 'service', 'providerProfile.user']);
            if (! DB::table('reservation_hold_clock')->where('id', 1)->exists()) {
                throw new LogicException('No se puede observar el reloj comercial.');
            }
            $now = $observedAt ?? BookingClock::observedMicros();
            $date = BookingClock::date($now);
            $hold = $reservation->hold;
            $snapshot = null;
            $blockers = [];
            if ($reservation->quote_id) {
                $quote = $this->store->quote($reservation->quote_id);
                if (! $quote || $quote->clientId !== $reservation->client_user_id || $quote->inquiryId !== $reservation->service_inquiry_id
                    || $quote->serviceId !== $reservation->service_id || $quote->providerProfileId !== $reservation->provider_profile_id
                    || $quote->price->serviceId !== $quote->serviceId || $quote->price->providerProfileId !== $quote->providerProfileId) {
                    throw new LogicException('El vínculo comercial no pudo comprobarse.');
                }
                $snapshot = $this->quotes->snapshot($quote, $date->toDateTimeImmutable());
                if (! $snapshot->acceptance) {
                    $blockers[] = 'quote_not_accepted';
                }
            } else {
                $blockers[] = 'commercial_quote_required';
            }
            if ($reservation->status !== ReservationStatus::Requested) {
                $blockers[] = 'reservation_not_pending';
            }
            if ($reservation->inquiry->status !== InquiryStatus::Accepted) {
                $blockers[] = 'inquiry_not_accepted';
            }
            if (! $hold) {
                $blockers[] = 'hold_not_started';
            } else {
                if (! $reservation->quote_id || $hold->expires_at_us - $hold->accepted_at_us !== BookingClock::HOLD_MICROSECONDS
                    || $hold->accepted_by !== $reservation->providerProfile->user_id
                    || ! in_array($hold->state, ['held', 'committed', 'expired', 'released'], true)
                    || $hold->accepted_at_us > $now) {
                    throw new LogicException('La retención no pudo comprobarse.');
                }
                if ($hold->state !== 'held' || $hold->remainingSeconds($now) === 0) {
                    $blockers[] = 'hold_not_active';
                }
            }
            if ($reservation->starts_at_utc <= $date->format('Y-m-d H:i:s')) {
                $blockers[] = 'service_already_started';
            }
            if (! $reservation->client->hasPermission(Permission::ManageOwnInquiries)
                || ! $reservation->providerProfile->user->hasPermission(Permission::ManageReceivedInquiries)) {
                $blockers[] = 'participants_unavailable';
            }

            // A live B2 hold freezes its slot: later price/schedule/publication edits
            // are not new blockers. Quote TTL bounds first acceptance only.
            return ['reservation' => ['id' => $reservation->id, 'inquiry_id' => $reservation->service_inquiry_id,
                'status' => $reservation->effectiveStatus($now)->value, 'version' => $reservation->version,
                'starts_at' => CarbonImmutable::parse($reservation->starts_at_utc, 'UTC')->toISOString(),
                'ends_at' => CarbonImmutable::parse($reservation->ends_at_utc, 'UTC')->toISOString(), 'timezone' => $reservation->timezone],
                'client' => ['id' => $actor->id, 'name' => $reservation->client->name],
                'service' => ['id' => $reservation->service_id, 'title' => $reservation->inquiry->service_title],
                'provider' => ['id' => $reservation->provider_profile_id, 'display_name' => $reservation->providerProfile->display_name],
                'quote' => $snapshot, 'amount' => $snapshot?->quote->price->amount,
                'hold' => $hold ? ['state' => $hold->state === 'held' && $hold->remainingSeconds($now) === 0 ? 'expired' : $hold->state,
                    'stored_state' => $hold->state, 'version' => $hold->version, 'hold_seconds' => intdiv(BookingClock::HOLD_MICROSECONDS, 1000000),
                    'accepted_at' => BookingClock::date($hold->accepted_at_us)->toISOString(),
                    'expires_at' => BookingClock::date($hold->expires_at_us)->toISOString(), 'remaining_seconds' => $hold->remainingSeconds($now)] : null,
                'server_now' => $date->toISOString(),
                'future_payment' => ['enabled' => false, 'can_start' => false, 'advisory_only' => true,
                    'commercial_eligible' => $blockers === [], 'blocking_reasons' => ['payments_not_enabled', ...$blockers]],
                'confirmation_policy' => ['requires_verified_payment' => false, 'protocol' => $reservation->quote_id ? 'quoted-slot-hold-v1' : 'legacy'],
                'confirmation_readiness' => app(ConfirmationReadiness::class)->forReservation($reservation, $blockers, $now)];
        }, 3);
    }
}
