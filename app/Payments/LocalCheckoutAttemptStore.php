<?php

namespace App\Payments;

use App\Enums\Permission;
use App\Enums\ReservationStatus;
use App\Models\ServiceReservation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Disposable-only test outcome shared by client and provider.
 * Its table exists ONLY in a B3.2 fixture, never in application migrations.
 */
final class LocalCheckoutAttemptStore
{
    public function current(int $reservationId): ?array
    {
        $row = DB::table('synthetic_checkout_attempts')->where('reservation_id', $reservationId)->first();

        return $row ? (array) $row : null;
    }

    /**
     * The B3.2 demo needs an accepted, immutable synthetic choice before B2
     * commits a quoted hold. Cash pending is a valid choice, not cash received.
     * Call only after verifying this is the disposable local checkout fixture.
     */
    public function permitsCommit(ServiceReservation $reservation): bool
    {
        if (! $reservation->quote_id) {
            return false;
        }

        $row = DB::table('synthetic_checkout_attempts')
            ->where('reservation_id', $reservation->id)
            ->first();
        // Reservation pages intentionally eager-load only display_name; resolve
        // the owner from the authoritative profile row, not a partial relation.
        $providerId = DB::table('provider_profiles')
            ->where('id', $reservation->provider_profile_id)
            ->value('user_id');
        if (! $row || $providerId === null
            || (int) $row->client_user_id !== $reservation->client_user_id
            || $row->quote_id !== $reservation->quote_id
            || (int) $row->provider_user_id !== (int) $providerId) {
            return false;
        }

        $total = DB::table('service_quotes')->where('id', $reservation->quote_id)->value('total_cents');

        return $total !== null && (int) $row->amount_cents === (int) $total
            && (($row->method === 'card' && $row->status === 'test_approved')
                || ($row->method === 'cash' && in_array($row->status, ['awaiting_cash', 'cash_received'], true)));
    }

    public function submit(User $client, array $context, string $method, string $scenario, string $key): array
    {
        $id = (int) $context['reservation']['id'];
        $quoteId = (string) $context['quote']->quote->id;
        $amountCents = $context['amount']->totalCents;
        $providerId = ServiceReservation::with('providerProfile')->findOrFail($id)->providerProfile->user_id;
        $status = $method === 'cash' ? 'awaiting_cash' : $scenario;

        return DB::transaction(function () use ($id, $quoteId, $amountCents, $client, $providerId, $method, $status, $key) {
            $existing = DB::table('synthetic_checkout_attempts')->where('reservation_id', $id)->lockForUpdate()->first();
            if ($existing) {
                abort_unless($existing->client_user_id === $client->id && $existing->quote_id === $quoteId
                    && (int) $existing->amount_cents === $amountCents && $existing->provider_user_id === $providerId, 409);
                if ($existing->idempotency_key === $key) {
                    abort_unless($existing->method === $method && $existing->scenario === $status, 409);

                    return (array) $existing;
                }
                abort_unless(in_array($existing->status, ['declined', 'temporary_failure'], true), 409);
            }

            $values = [
                'client_user_id' => $client->id,
                'provider_user_id' => $providerId,
                'quote_id' => $quoteId,
                'amount_cents' => $amountCents,
                'method' => $method,
                'status' => $status,
                'scenario' => $status,
                'idempotency_key' => $key,
                'reference' => 'TK-TEST-'.strtoupper(Str::random(12)),
                'attempted_at' => now('UTC')->toISOString(),
                'received_at' => null,
            ];
            if ($existing) {
                DB::table('synthetic_checkout_attempts')->where('reservation_id', $id)
                    ->whereIn('status', ['declined', 'temporary_failure'])->update($values);
            } else {
                DB::table('synthetic_checkout_attempts')->insert(['reservation_id' => $id] + $values);
            }

            return $this->current($id) ?? abort(503);
        }, 3);
    }

    public function receiveCash(User $provider, ServiceReservation $reservation): array
    {
        abort_unless($reservation->providerProfile->user_id === $provider->id
            && $provider->hasPermission(Permission::ManageReceivedInquiries), 403);

        return DB::transaction(function () use ($reservation, $provider) {
            $row = $this->current($reservation->id);
            abort_unless($row && (int) $row['provider_user_id'] === $provider->id
                && $row['method'] === 'cash', 404);
            if ($row['status'] === 'cash_received') {
                return $row;
            }
            abort_unless($row['status'] === 'awaiting_cash', 409);
            $current = $reservation->fresh();
            $isLiveHold = $current->status === ReservationStatus::Requested
                && $current->hold?->state === 'held'
                && $current->hold->remainingSeconds() > 0;
            $isCommittedBooking = $current->status === ReservationStatus::Confirmed
                && $current->hold?->state === 'committed';
            abort_unless($isLiveHold || $isCommittedBooking, 409);
            DB::table('synthetic_checkout_attempts')
                ->where('reservation_id', $reservation->id)->where('status', 'awaiting_cash')
                ->update(['status' => 'cash_received', 'received_at' => now('UTC')->toISOString()]);

            return $this->current($reservation->id) ?? abort(503);
        }, 3);
    }
}
