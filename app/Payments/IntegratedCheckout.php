<?php

namespace App\Payments;

use App\Enums\InquiryStatus;
use App\Enums\Permission;
use App\Enums\ReservationStatus;
use App\Exceptions\ReservationFailure;
use App\Models\ProviderProfile;
use App\Models\ReservationHold;
use App\Models\ServiceInquiry;
use App\Models\ServiceReservation;
use App\Models\User;
use App\Services\ReservationTransactions;
use App\Support\BookingClock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use PDOException;

final class IntegratedCheckout
{
    private const TABLE = 'integrated_checkout_attempts';

    private const RETRYABLE = ['declined', 'temporary_failure'];

    public function __construct(private readonly SimulatedCheckoutProcessor $processor) {}

    public static function enabled(): bool
    {
        return config('checkout.processor') === 'simulated' && DB::connection()->getDriverName() === 'mysql';
    }

    public static function bindsNewQuote(): bool
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return false;
        }

        return match (config('checkout.processor')) {
            'disabled' => false,
            'simulated' => true,
            default => throw new ReservationFailure('El procesador simulado no está configurado.', 503),
        };
    }

    public static function requiresChoice(ServiceReservation $reservation): bool
    {
        return (int) $reservation->checkout_required === 1;
    }

    public function permitsCommit(ServiceReservation $reservation): bool
    {
        return ! self::requiresChoice($reservation) || $this->selection($reservation) !== null;
    }

    private function selection(ServiceReservation $reservation): ?object
    {
        $quote = $this->quote($reservation);
        $provider = $this->provider($reservation);
        if (! $quote || ! $provider) {
            return null;
        }

        return ReservationTransactions::current(DB::table(self::TABLE)->where('successful_reservation', $reservation->id)
            ->where('quote_id', $quote->id)->where('client_user_id', $reservation->client_user_id)
            ->where('provider_user_id', $provider->user_id)->where('amount_cents', (int) $quote->total_cents)
            ->where('commission_cents', (int) $quote->commission_cents))->first();
    }

    public function clientReservation(User $actor, int $id): ServiceReservation
    {
        $item = ReservationTransactions::current(ServiceReservation::where('client_user_id', $actor->id))->findOrFail($id);
        abort_unless(self::requiresChoice($item), 404);
        Gate::forUser($actor)->authorize('commit', $item);

        return $item;
    }

    public function providerReservation(User $actor, int $id): ServiceReservation
    {
        $item = ReservationTransactions::current(ServiceReservation::query())->findOrFail($id);
        abort_unless(self::requiresChoice($item) && $this->provider($item)?->user_id === $actor->id, 404);
        Gate::forUser($actor)->authorize('confirm', $item);

        return $item;
    }

    public function submit(User $actor, int $id, array $input): array
    {
        return $this->write(function () use ($actor, $id, $input) {
            $reservation = $this->clientReservation($actor, $id);
            [$method, $key] = $this->input($input);
            $existing = ReservationTransactions::current(DB::table(self::TABLE)->where('client_user_id', $actor->id)
                ->where('idempotency_key', $key))->first();
            if ($existing) {
                abort_unless((int) $existing->reservation_id === $id && $existing->method === $method, 409);

                return $this->receipt($existing);
            }

            abort_unless(self::enabled(), 503);
            abort_if($this->selection($reservation), 409);
            $quote = $this->quote($reservation);
            $provider = $this->provider($reservation);
            $inquiry = ReservationTransactions::current(ServiceInquiry::query())->findOrFail($reservation->service_inquiry_id);
            abort_unless($quote && $provider && $inquiry->status === InquiryStatus::Accepted
                && $provider->user->hasPermission(Permission::ManageReceivedInquiries), 409);
            $outcome = $method === 'card' ? $this->processor->cardOutcome() : 'awaiting_cash';
            abort_unless(in_array($outcome, $method === 'card' ? ['test_approved', ...self::RETRYABLE] : ['awaiting_cash'], true), 503);
            $now = BookingClock::micros();
            abort_unless($this->liveHold($reservation, $now), 409);
            $attempt = ['reservation_id' => $id, 'quote_id' => $quote->id, 'client_user_id' => $actor->id,
                'provider_user_id' => $provider->user_id, 'amount_cents' => (int) $quote->total_cents,
                'commission_cents' => (int) $quote->commission_cents, 'currency' => 'MXN', 'method' => $method, 'status' => $outcome,
                'reference' => (string) Str::uuid(), 'idempotency_key' => $key,
                'attempted_at' => BookingClock::date($now)->format('Y-m-d H:i:s.u'), 'received_at' => null];
            DB::table(self::TABLE)->insert($attempt);

            return $this->receipt((object) $attempt);
        });
    }

    public function receiveCash(User $actor, int $id): array
    {
        return $this->write(function () use ($actor, $id) {
            $item = $this->providerReservation($actor, $id);
            $attempt = $this->selection($item);
            abort_unless($attempt && $attempt->method === 'cash', 404);
            if ($attempt->status === 'cash_received') {
                return $this->receipt($attempt);
            }

            abort_unless(self::enabled(), 503);
            $now = BookingClock::micros();
            abort_unless($this->cashWindow($item, $now), 409);
            $changes = ['status' => 'cash_received', 'received_at' => BookingClock::date($now)->format('Y-m-d H:i:s.u')];
            abort_unless(DB::table(self::TABLE)->where('id', $attempt->id)->where('status', 'awaiting_cash')->update($changes) === 1, 409);

            return $this->receipt((object) ($changes + (array) $attempt));
        });
    }

    public function latest(ServiceReservation $item): ?array
    {
        $attempt = ReservationTransactions::current(DB::table(self::TABLE)->where('reservation_id', $item->id)->orderByDesc('id'))->first();

        return $attempt ? $this->receipt($attempt) : null;
    }

    public function providerSummary(User $actor, ServiceReservation $item): ?array
    {
        if (! self::requiresChoice($item) || $this->provider($item)?->user_id !== $actor->id
            || ! $actor->hasPermission(Permission::ManageReceivedInquiries)) {
            return null;
        }
        $attempt = $this->latest($item);
        if (! $attempt) {
            return null;
        }
        $now = BookingClock::observedMicros();
        $labels = ['test_approved' => 'Tarjeta aprobada (prueba)', 'declined' => 'Tarjeta rechazada (prueba)',
            'temporary_failure' => 'Fallo temporal (prueba)', 'awaiting_cash' => 'Efectivo pendiente de recepción',
            'cash_received' => 'Recepción registrada (prueba)'];

        return $attempt + ['label' => $labels[$attempt['status']], 'reservation_active' => $this->cashWindow($item, $now),
            'can_receive_cash' => self::enabled() && $attempt['status'] === 'awaiting_cash' && $this->cashWindow($item, $now),
            'cash_route' => 'checkout.integrated.cash'];
    }

    public function cashWindow(ServiceReservation $item, int $now): bool
    {
        return $this->liveHold($item, $now)
            || ($item->status === ReservationStatus::Confirmed && $this->hold($item)?->state === 'committed');
    }

    private function liveHold(ServiceReservation $item, int $now): bool
    {
        if ($item->status !== ReservationStatus::Requested || $item->starts_at_utc <= BookingClock::date($now)->format('Y-m-d H:i:s')) {
            return false;
        }
        $hold = $this->hold($item);

        return $hold?->state === 'held' && $hold->expires_at_us > $now;
    }

    private function hold(ServiceReservation $item): ?ReservationHold
    {
        return ReservationTransactions::current($item->hold()->getQuery())->first();
    }

    private function provider(ServiceReservation $item): ?ProviderProfile
    {
        return ReservationTransactions::current($item->providerProfile()->getQuery())->first();
    }

    private function quote(ServiceReservation $item): ?object
    {
        return ReservationTransactions::current(DB::table('service_quotes as q')
            ->join('quote_acceptances as a', 'a.quote_id', '=', 'q.id')->where('q.id', $item->quote_id)
            ->where('q.service_inquiry_id', $item->service_inquiry_id)->where('q.service_id', $item->service_id)
            ->where('q.client_user_id', $item->client_user_id)->where('a.client_user_id', $item->client_user_id)
            ->where('q.provider_profile_id', $item->provider_profile_id))->first(['q.id', 'q.total_cents', 'q.commission_cents']);
    }

    private function input(array $input): array
    {
        // Deliberately avoid redirect validation and flashed request payloads.
        abort_unless(array_diff(array_keys($input), ['method', 'instrument', 'idempotency_key']) === [], 422);
        $method = $input['method'] ?? null;
        $key = $input['idempotency_key'] ?? null;
        abort_unless(in_array($method, ['card', 'cash'], true) && is_string($key) && Str::isUuid($key), 422);
        abort_unless($method === 'card' ? ($input['instrument'] ?? null) === SimulatedCheckoutProcessor::INSTRUMENT
            : ! array_key_exists('instrument', $input), 422);

        return [$method, strtolower($key)];
    }

    private function write(callable $action): array
    {
        try {
            return app(ReservationTransactions::class)->run($action);
        } catch (PDOException $error) {
            $native = $error->errorInfo[1] ?? null;
            $conflict = in_array($native, [1062, 1205, 1213], true)
                || ($native === 1644 && ($error->errorInfo[2] ?? null) === 'integrated_checkout_ineligible');
            throw new ReservationFailure($conflict ? 'Consulta el estado de la reserva antes de reintentar.'
                : 'No se pudo guardar el resultado de prueba.', $conflict ? 409 : 503);
        }
    }

    private function receipt(object $attempt): array
    {
        return ['reservation_id' => (int) $attempt->reservation_id, 'quote_id' => $attempt->quote_id,
            'method' => $attempt->method, 'status' => $attempt->status, 'reference' => $attempt->reference,
            'currency' => $attempt->currency, 'amount_cents' => (int) $attempt->amount_cents,
            'commission_cents' => (int) $attempt->commission_cents,
            'provider_cents' => (int) $attempt->amount_cents - (int) $attempt->commission_cents,
            'attempted_at' => str_replace(' ', 'T', $attempt->attempted_at).'Z',
            'received_at' => $attempt->received_at === null ? null : str_replace(' ', 'T', $attempt->received_at).'Z',
            'synthetic_only' => true];
    }
}
