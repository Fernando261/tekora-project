<?php

namespace App\Payments;

use App\Enums\Permission;
use App\Enums\ReservationStatus;
use App\Http\Controllers\LocalIntegratedCheckoutController;
use App\Models\ServiceReservation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only B3.2 status for the owning WEB provider.
 * No access to the synthetic table, or even its schema, outside the private fixture.
 */
final class ProviderSyntheticCheckoutSummary
{
    public function forReservation(Request $request, ?ServiceReservation $reservation): ?array
    {
        if ($reservation && $request->user('web') && IntegratedCheckout::requiresChoice($reservation)) {
            return app(IntegratedCheckout::class)->providerSummary($request->user('web'), $reservation);
        }
        if (! $reservation || ! $reservation->quote_id ||
            ! LocalIntegratedCheckoutController::permitsSyntheticRequest($request)) {
            return null;
        }

        $actor = $request->user('web');
        if (! $actor || ! $actor->hasPermission(Permission::ManageReceivedInquiries)
            || ! DB::table('provider_profiles')->where('id', $reservation->provider_profile_id)
                ->where('user_id', $actor->id)->exists()
            || ! Schema::hasTable('synthetic_checkout_attempts')) {
            return null;
        }

        // Check the exact immutable commercial binding before exposing an attempt.
        $quote = DB::table('service_quotes')->where('id', $reservation->quote_id)
            ->where('service_inquiry_id', $reservation->service_inquiry_id)
            ->where('service_id', $reservation->service_id)
            ->where('provider_profile_id', $reservation->provider_profile_id)
            ->where('client_user_id', $reservation->client_user_id)
            ->first();
        if (! $quote || (int) $quote->total_cents < 1) {
            return null;
        }

        $attempt = app(LocalCheckoutAttemptStore::class)->current($reservation->id);
        if ($attempt && (
            (int) $attempt['client_user_id'] !== $reservation->client_user_id
            || (int) $attempt['provider_user_id'] !== $actor->id
            || $attempt['quote_id'] !== $reservation->quote_id
            || (int) $attempt['amount_cents'] !== (int) $quote->total_cents
            || ! in_array($attempt['method'], ['cash', 'card'], true)
            || ! in_array($attempt['status'], ['test_approved', 'declined', 'temporary_failure', 'awaiting_cash', 'cash_received'], true)
        )) {
            return null;
        }

        $method = $attempt['method'] ?? null;
        $status = $attempt['status'] ?? 'not_selected';
        $valid = $attempt && app(LocalCheckoutAttemptStore::class)->permitsCommit($reservation);
        $terminal = in_array($reservation->effectiveStatus(), [
            ReservationStatus::Cancelled, ReservationStatus::Rejected,
            ReservationStatus::HoldExpired, ReservationStatus::HoldReleased,
        ], true);
        $confirmed = in_array($reservation->status, [ReservationStatus::Confirmed, ReservationStatus::Completed], true);
        $label = match (true) {
            $method === 'card' && $status === 'test_approved' && $valid && $confirmed => 'Pagado (prueba)',
            $method === 'card' && $status === 'test_approved' && $valid && $terminal => 'Aprobación de prueba histórica · reserva no activa',
            $method === 'card' && $status === 'test_approved' && $valid => 'Tarjeta aprobada (prueba) · falta confirmar',
            $method === 'cash' && $status === 'cash_received' && $valid && $terminal => 'Efectivo recibido (prueba) · reserva no activa',
            $method === 'cash' && $status === 'cash_received' && $valid => 'Efectivo recibido (prueba)',
            $method === 'cash' && $status === 'awaiting_cash' && $valid && $terminal => 'Efectivo no recibido · reserva no activa',
            $method === 'cash' && $status === 'awaiting_cash' && $valid => 'Efectivo pendiente',
            $status === 'declined' => 'Tarjeta rechazada (prueba)',
            $status === 'temporary_failure' => 'Operación de prueba no completada',
            default => 'Sin método de prueba seleccionado',
        };

        return [
            'reservation_id' => $reservation->id,
            'quote_id' => $reservation->quote_id,
            'status' => $status,
            'method' => $method,
            'label' => $label,
            'amount_cents' => (int) $quote->total_cents,
            'reference' => $attempt['reference'] ?? null,
            'attempted_at' => $attempt['attempted_at'] ?? null,
            'received_at' => $attempt['received_at'] ?? null,
            'reservation_active' => ! $terminal,
            'can_receive_cash' => $method === 'cash' && $status === 'awaiting_cash'
                && $valid && ! $terminal && in_array($reservation->effectiveStatus(), [ReservationStatus::Held, ReservationStatus::Confirmed], true),
        ];
    }
}
