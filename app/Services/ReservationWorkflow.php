<?php

namespace App\Services;

use App\Enums\InquiryStatus;
use App\Enums\Permission;
use App\Enums\ReservationStatus;
use App\Exceptions\InquiryConflict;
use App\Exceptions\ReservationFailure;
use App\Models\ProviderAvailabilitySetting;
use App\Models\ProviderProfile;
use App\Models\Service;
use App\Models\ServiceInquiry;
use App\Models\ServiceReservation;
use App\Models\User;
use App\Support\BookingClock;
use App\Support\ReservationInput;
use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ReservationWorkflow
{
    use DetectsConcurrencyErrors;

    // Lectura coherente del mismo origen que propose(); no obtiene un bloqueo de escritura.
    public function options(User $actor, int $inquiryId): array
    {
        return $this->safely(function () use ($actor, $inquiryId) {
            return DB::transaction(function () use ($actor, $inquiryId) {
                $inquiry = ServiceInquiry::where('client_user_id', $actor->id)->findOrFail($inquiryId);
                Gate::forUser($actor)->authorize('propose', [ServiceReservation::class, $inquiry]);
                if ($inquiry->status !== InquiryStatus::Accepted || $inquiry->reservations()->active()->exists()) {
                    throw new ReservationFailure;
                }
                $setting = $this->setting($inquiry);
                $schedule = app(ProviderAvailability::class)->publicFor($inquiry->service->providerProfile);
                if (isset($schedule['unavailable'])) {
                    throw new ReservationFailure('Lectura temporalmente no disponible.', 503);
                }
                if (! $schedule || $schedule['days']->isEmpty()) {
                    throw new ReservationFailure;
                }
                $today = now($setting->timezone)->startOfDay();

                $commercial = $this->quoteOptions($inquiry);

                return $commercial + ['inquiry_id' => $inquiry->id, 'service_id' => $inquiry->service_id,
                    'revision' => $setting->revision, 'schedule' => $schedule,
                    'min_date' => $today->format('Y-m-d'),
                    'max_date' => $today->addDays(max(1, (int) config('reservations.horizon_days', 90)))->format('Y-m-d')];
            }, 3);
        });
    }

    /** @return array{reservation: ServiceReservation, created: bool} */
    public function propose(User $actor, int $inquiryId, array $input): array
    {
        return $this->safely(function () use ($actor, $inquiryId, $input) {
            return app(ReservationTransactions::class)->run(function () use ($actor, $inquiryId, $input) {
                $inquiry = ReservationTransactions::current(ServiceInquiry::query())->findOrFail($inquiryId);
                Gate::forUser($actor)->authorize('propose', [ServiceReservation::class, $inquiry]);
                $data = ReservationInput::proposal($input);
                $key = strtolower($data['idempotency_key']);
                $existing = ReservationTransactions::current(ServiceReservation::where('client_user_id', $actor->id))->where('service_inquiry_id', $inquiryId)->where('idempotency_key', $key)->first();
                if ($existing) {
                    if ($existing->local_date !== $data['date'] || $existing->localTime() !== $data['time'] || $existing->availability_revision !== (int) $data['availability_revision'] || $existing->quote_id !== (isset($data['quote_id']) ? strtolower($data['quote_id']) : null)) {
                        throw new ReservationFailure('Este identificador ya se usó para otra propuesta. Abre un formulario nuevo.');
                    }

                    return ['reservation' => $existing, 'created' => false];
                }
                $this->lockInquiry($inquiryId);
                $inquiry = ReservationTransactions::current(ServiceInquiry::query())->findOrFail($inquiry->id);
                $this->lockParents($inquiry);
                Gate::forUser($actor)->authorize('propose', [ServiceReservation::class, $inquiry]);
                if ($inquiry->reservations()->active()->exists()) {
                    throw new ReservationFailure('Esta solicitud ya tiene una propuesta o reserva activa. Consulta su estado.');
                }
                $setting = $this->setting($inquiry);
                if ($setting->revision !== (int) $data['availability_revision']) {
                    throw new ReservationFailure('La disponibilidad cambió. Abre un formulario nuevo para consultar la zona y los horarios actuales.');
                }
                $slot = app(ReservationTime::class)->resolve($data['date'], $data['time'], $setting->timezone);
                $this->available($inquiry, $setting, $data['date'], $slot);

                $quoteId = $this->proposalQuote($inquiry, $data);
                $reservation = new ServiceReservation;
                $reservation->quote_id = $quoteId;
                $reservation->service_inquiry_id = $inquiry->id;
                $reservation->service_id = $inquiry->service_id;
                $reservation->client_user_id = $inquiry->client_user_id;
                $reservation->provider_profile_id = $inquiry->service->provider_profile_id;
                $reservation->timezone = $setting->timezone;
                $reservation->local_date = $data['date'];
                $reservation->local_start_minute = $slot['local_start_minute'];
                $reservation->starts_at_utc = $slot['starts_at_utc'];
                $reservation->ends_at_utc = $slot['ends_at_utc'];
                $reservation->availability_revision = $setting->revision;
                $reservation->idempotency_key = $key;
                $reservation->status = ReservationStatus::Requested;
                $reservation->version = 1;
                $reservation->last_actor_user_id = $actor->id;
                $reservation->last_reason = 'proposal';
                $reservation->created_at = $reservation->updated_at = $this->commandTime()->format('Y-m-d H:i:s');
                $reservation->save();

                return ['reservation' => $reservation->fresh(), 'created' => true];
            });
        });
    }

    public function decide(User $actor, int $reservationId, string $action, array $input): ServiceReservation
    {
        return $this->safely(function () use ($actor, $reservationId, $action, $input) {
            return app(ReservationTransactions::class)->run(function () use ($actor, $reservationId, $action, $input) {
                abort_unless(in_array($action, ['confirm', 'commit', 'reject', 'cancel', 'complete'], true), 404);
                $reservation = ReservationTransactions::current(ServiceReservation::query())->findOrFail($reservationId);
                Gate::forUser($actor)->authorize($action, $reservation);
                $data = ReservationInput::decision($input);
                if ($reservation->quote_id) {
                    return $this->decideQuoted($actor, $reservation, $action, $data);
                }
                if ($action === 'commit') {
                    throw new ReservationFailure('Esta reserva no tiene una retención comercial que confirmar.');
                }
                $this->lockInquiry($reservation->service_inquiry_id);
                $reservation = ReservationTransactions::current(ServiceReservation::query())->findOrFail($reservation->id);
                Gate::forUser($actor)->authorize($action, $reservation);
                if (ReservationTransactions::writingMysql()) {
                    $inquiry = ReservationTransactions::current(ServiceInquiry::query())->findOrFail($reservation->service_inquiry_id);
                    $this->lockParents($inquiry);
                    $reservation->setRelation('inquiry', $inquiry);
                }
                if ($reservation->version !== (int) $data['version']) {
                    throw new ReservationFailure;
                }
                $now = $this->commandTime()->format('Y-m-d H:i:s');
                $target = match ($action) {
                    'confirm' => ReservationStatus::Confirmed, 'reject' => ReservationStatus::Rejected,
                    'cancel' => ReservationStatus::Cancelled, 'complete' => ReservationStatus::Completed,
                };
                $source = $reservation->status;
                $allowed = match ($action) {
                    'confirm' => $source === ReservationStatus::Requested && $reservation->starts_at_utc > $now,
                    'reject' => $source === ReservationStatus::Requested,
                    'cancel' => $source === ReservationStatus::Requested || ($source === ReservationStatus::Confirmed && $reservation->starts_at_utc > $now),
                    'complete' => $source === ReservationStatus::Confirmed && $reservation->ends_at_utc <= $now,
                };
                if (! $allowed) {
                    throw new ReservationFailure('La propuesta expiró, ya fue decidida o la acción no corresponde a su estado y horario actuales.');
                }
                if ($action === 'confirm') {
                    $inquiry = $reservation->inquiry;
                    $setting = $this->setting($inquiry);
                    if ($setting->timezone !== $reservation->timezone) {
                        throw new ReservationFailure('La zona horaria cambió desde la propuesta. Recházala o cancélala y prepara una nueva.');
                    }
                    $slot = app(ReservationTime::class)->resolve($reservation->local_date, $reservation->localTime(), $setting->timezone);
                    if ($slot['starts_at_utc'] !== $reservation->starts_at_utc || $slot['ends_at_utc'] !== $reservation->ends_at_utc) {
                        throw new ReservationFailure('Las reglas de la zona horaria cambiaron. Prepara una propuesta nueva.');
                    }
                    $this->available($inquiry, $setting, $reservation->local_date, $slot);
                }
                $this->transition($reservation, $target, $actor, $action);

                return ReservationTransactions::current(ServiceReservation::query())->findOrFail($reservation->id);
            });
        });
    }

    // InquiryWorkflow conserva el mismo bloqueo que propuestas y decisiones. Cerrar no decide reservas.
    public function closeForInquiry(ServiceInquiry $inquiry, User $actor): void
    {
        $this->safely(function () use ($inquiry, $actor) {
            if (DB::transactionLevel() < 1) {
                throw new \LogicException('El cierre conjunto requiere la transacción de InquiryWorkflow.');
            }
            $this->lockInquiry($inquiry->id);
            $inquiry = ReservationTransactions::current(ServiceInquiry::query())->findOrFail($inquiry->id);
            $this->lockParents($inquiry);
            Gate::forUser($actor)->authorize('complete', $inquiry);
            $blocker = $this->inquiryCompletionBlocker($inquiry);
            if ($blocker !== null) {
                throw new InquiryConflict($inquiry->id, $blocker);
            }
        });
    }

    // Orienta la vista; el cierre vuelve a consultar esta misma regla bajo bloqueo de escritura.
    public function inquiryCompletionBlocker(ServiceInquiry $inquiry): ?string
    {
        $now = $this->commandTime()->format('Y-m-d H:i:s');
        $active = $inquiry->reservations()->active()->first();
        if ($active?->effectiveStatus() === ReservationStatus::Held) {
            return 'Primero el cliente debe confirmar la retención y el prestador marcar la reserva como realizada después de su horario.';
        }
        if ($active?->status === ReservationStatus::Requested) {
            return 'Primero debes confirmar una reserva para este servicio. La propuesta pendiente no se cancela al finalizar la solicitud.';
        }
        if ($active?->status === ReservationStatus::Confirmed) {
            return $active->ends_at_utc > $now
                ? 'El servicio todavía está programado. Espera a que termine el horario y marca la reserva como realizada.'
                : 'El horario ya terminó. Marca la reserva como realizada antes de finalizar la solicitud.';
        }
        $performed = ReservationTransactions::current($inquiry->reservations()->getQuery())->where('status', ReservationStatus::Completed->value)
            ->where('last_reason', 'complete')->where('ends_at_utc', '<=', $now)
            ->whereHas('events', fn ($events) => ReservationTransactions::current($events)->where('from_status', 'requested')->where('to_status', 'confirmed')
                ->where('reason', 'confirm')->whereColumn('reservation_events.version', '<', 'service_reservations.version'))
            ->whereHas('events', fn ($events) => ReservationTransactions::current($events)->where('from_status', 'confirmed')->where('to_status', 'completed')->where('reason', 'complete')
                ->whereColumn('reservation_events.version', 'service_reservations.version')
                ->whereColumn('reservation_events.occurred_at_utc', '>=', 'service_reservations.ends_at_utc'))->exists();

        return $performed ? null : 'Primero debes confirmar una reserva para este servicio y marcarla como realizada cuando termine su horario.';
    }

    public function refreshExpirations(): void
    {
        // GET stays read-only unless a real expiration needs durable reconciliation.
        if (DB::table('reservation_holds')->where('state', 'held')->whereRaw('expires_at_us <= '.BookingClock::observedSql())->exists()) {
            $this->safely(fn () => app(ReservationTransactions::class)->run(fn () => null));
        }
    }

    public function quoteOptions(ServiceInquiry $inquiry): array
    {
        if (! ReservationTransactions::current(DB::table('service_price_versions')->where('service_id', $inquiry->service_id))->exists()) {
            return [];
        }
        $quotes = DB::table('service_quotes as q')->join('quote_acceptances as a', 'a.quote_id', '=', 'q.id')
            ->where('q.service_inquiry_id', $inquiry->id)->where('q.service_id', $inquiry->service_id)
            ->where('q.client_user_id', $inquiry->client_user_id)->where('a.client_user_id', $inquiry->client_user_id)
            ->where('q.provider_profile_id', $inquiry->service->provider_profile_id)->orderByDesc('a.accepted_at_utc')
            ->get(['q.id', 'q.total_cents', 'q.currency', 'q.duration_minutes', 'q.price_version', 'a.accepted_at_utc']);
        if (DB::connection()->getDriverName() === 'mysql') {
            // The InnoDB checks guarantee these exact integers; PDO returns VARCHAR/DECIMAL as strings.
            foreach ($quotes as $quote) {
                $quote->total_cents = (int) $quote->total_cents;
                $quote->duration_minutes = (int) $quote->duration_minutes;
                $quote->price_version = (int) $quote->price_version;
            }
        }

        return ['commercial' => ['requires_accepted_quote' => true, 'hold_seconds' => 900, 'accepted_quotes' => $quotes->all(),
            'quotes_web_url' => route('inquiries.show', $inquiry).'#cotizaciones']];
    }

    private function proposalQuote(ServiceInquiry $inquiry, array $input): ?string
    {
        $priced = ReservationTransactions::current(DB::table('service_price_versions')->where('service_id', $inquiry->service_id))->exists();
        if (! $priced) {
            if (! empty($input['quote_id'])) {
                throw ValidationException::withMessages(['quote_id' => 'Este servicio no utiliza cotización comercial.']);
            }

            return null;
        }
        if (empty($input['quote_id'])) {
            throw ValidationException::withMessages(['quote_id' => 'Selecciona una cotización aceptada para esta solicitud.']);
        }
        $id = strtolower($input['quote_id']);
        $valid = ReservationTransactions::current(DB::table('service_quotes as q')->join('quote_acceptances as a', 'a.quote_id', '=', 'q.id'))
            ->where('q.id', $id)->where('q.service_inquiry_id', $inquiry->id)->where('q.service_id', $inquiry->service_id)
            ->where('q.provider_profile_id', $inquiry->service->provider_profile_id)->where('q.client_user_id', $inquiry->client_user_id)
            ->where('a.client_user_id', $inquiry->client_user_id)->exists();
        if (! $valid) {
            throw ValidationException::withMessages(['quote_id' => 'La cotización no está aceptada por el cliente de esta solicitud.']);
        }

        return $id;
    }

    private function decideQuoted(User $actor, ServiceReservation $reservation, string $action, array $input): ServiceReservation
    {
        if (empty($input['idempotency_key'])) {
            throw ValidationException::withMessages(['idempotency_key' => 'La operación comercial requiere una clave de envío.']);
        }
        $key = strtolower($input['idempotency_key']);
        $receipt = ReservationTransactions::current(DB::table('reservation_action_receipts')->where('actor_user_id', $actor->id)->where('idempotency_key', $key))->first();
        if ($receipt) {
            if ($receipt->service_reservation_id !== $reservation->id || $receipt->action !== $action || (int) $receipt->expected_version !== (int) $input['version']) {
                throw new ReservationFailure('La clave ya pertenece a otra operación o contenido.');
            }

            return ReservationTransactions::current(ServiceReservation::query())->findOrFail($reservation->id);
        }
        $this->lockInquiry($reservation->service_inquiry_id);
        $reservation = ReservationTransactions::current(ServiceReservation::query())->findOrFail($reservation->id);
        Gate::forUser($actor)->authorize($action, $reservation);
        if ($reservation->version !== (int) $input['version']) {
            throw new ReservationFailure;
        }
        $inquiry = ReservationTransactions::current(ServiceInquiry::query())->findOrFail($reservation->service_inquiry_id);
        $this->lockParents($inquiry);
        $reservation->setRelation('inquiry', $inquiry);
        $hold = ReservationTransactions::current($reservation->hold()->getQuery())->first();
        $state = $reservation->status;
        $now = $this->commandTime()->format('Y-m-d H:i:s');
        if ($hold && in_array($hold->state, ['expired', 'released'], true)) {
            throw new ReservationFailure('La retención terminó. Prepara otra propuesta; no se reactiva ni renueva.');
        }
        if ($action === 'confirm') {
            if ($state !== ReservationStatus::Requested || $hold) {
                throw new ReservationFailure('Esta propuesta ya fue aceptada o decidida.');
            }
            $this->proposalQuote($reservation->inquiry, ['quote_id' => $reservation->quote_id]);
            $setting = $this->setting($reservation->inquiry);
            if ($setting->timezone !== $reservation->timezone) {
                throw new ReservationFailure('La zona horaria cambió. Prepara otra propuesta.');
            }
            $slot = app(ReservationTime::class)->resolve($reservation->local_date, $reservation->localTime(), $setting->timezone);
            if ($slot['starts_at_utc'] !== $reservation->starts_at_utc || $slot['ends_at_utc'] !== $reservation->ends_at_utc) {
                throw new ReservationFailure('El horario cambió. Prepara otra propuesta.');
            }
            $this->available($reservation->inquiry, $setting, $reservation->local_date, $slot);
            $accepted = \App\Support\BookingClock::micros();
            DB::table('reservation_holds')->insert(['service_reservation_id' => $reservation->id, 'accepted_by' => $actor->id,
                'accepted_at_us' => $accepted, 'expires_at_us' => $accepted + \App\Support\BookingClock::HOLD_MICROSECONDS,
                'state' => 'held', 'version' => 1, 'last_actor_user_id' => $actor->id, 'closed_at_us' => null]);
        } elseif ($action === 'commit') {
            if ($state !== ReservationStatus::Requested || ! $hold || $hold->state !== 'held'
                || (! ReservationTransactions::writingMysql() && $hold->expires_at_us <= BookingClock::micros()) || $reservation->starts_at_utc <= $now) {
                throw new ReservationFailure('La retención venció o el servicio ya comenzó. No se confirmó la reserva.');
            }
            // Provider acceptance freezes the slot. Later schedule/price edits do not displace it.
            if (! $reservation->client->hasPermission(Permission::ManageOwnInquiries) || ! $reservation->providerProfile->user->hasPermission(Permission::ManageReceivedInquiries)) {
                throw new ReservationFailure('Los participantes ya no permiten confirmar esta reserva.');
            }
            $this->proposalQuote($reservation->inquiry, ['quote_id' => $reservation->quote_id]);
            // The original B2 contract stays independent in every non-demo
            // environment. Only B3.2's private loopback SQLite fixture requires
            // the customer to select a synthetic payment method before commit.
            if (\App\Http\Controllers\LocalIntegratedCheckoutController::permitsSyntheticRequest(request())
                && ! app(\App\Payments\LocalCheckoutAttemptStore::class)->permitsCommit($reservation)) {
                throw new ReservationFailure('Primero elige y registra un método de pago de prueba antes de confirmar tu reserva.', 409);
            }
            if (ReservationTransactions::writingMysql()) {
                $sampled = BookingClock::micros();
                if ($hold->expires_at_us <= $sampled) {
                    DB::table('reservation_holds')->where('service_reservation_id', $reservation->id)->where('state', 'held')->update([
                        'state' => 'expired', 'version' => $hold->version + 1, 'last_actor_user_id' => null,
                        'closed_at_us' => $hold->expires_at_us,
                    ]);
                }
                if ($hold->expires_at_us <= $sampled || $reservation->starts_at_utc <= BookingClock::date($sampled)->format('Y-m-d H:i:s')) {
                    throw new ReservationFailure('La retención venció o el servicio ya comenzó. No se confirmó la reserva.');
                }
            }
            $this->transition($reservation, ReservationStatus::Confirmed, $actor, 'confirm');
        } else {
            $allowed = match ($action) {
                'reject' => $state === ReservationStatus::Requested && ! $hold,
                'cancel' => $state === ReservationStatus::Requested || ($state === ReservationStatus::Confirmed && $reservation->starts_at_utc > $now),
                'complete' => $state === ReservationStatus::Confirmed && $reservation->ends_at_utc <= $now,
                default => false,
            };
            if (! $allowed) {
                throw new ReservationFailure;
            }
            $target = match ($action) {
                'reject' => ReservationStatus::Rejected, 'cancel' => ReservationStatus::Cancelled, 'complete' => ReservationStatus::Completed
            };
            $this->transition($reservation, $target, $actor, $action);
        }
        $result = ReservationTransactions::current(ServiceReservation::query())->findOrFail($reservation->id);
        DB::table('reservation_action_receipts')->insert(['actor_user_id' => $actor->id, 'idempotency_key' => $key,
            'service_reservation_id' => $result->id, 'action' => $action, 'expected_version' => (int) $input['version'],
            'result_version' => $result->version, 'recorded_at_us' => \App\Support\BookingClock::micros()]);

        return $result;
    }

    private function lockInquiry(int $id): void
    {
        if (ReservationTransactions::writingMysql()) {
            if (ServiceInquiry::whereKey($id)->where('status', InquiryStatus::Accepted->value)->lockForUpdate()->first(['id']) === null) {
                throw new ReservationFailure('La solicitud ya no está aceptada. Consulta su estado actual.');
            }

            return;
        }
        // SQLite ignora FOR UPDATE: esta escritura condicional obtiene su bloqueo real, compartido con decisiones y mensajes.
        $changed = ServiceInquiry::whereKey($id)->where('status', InquiryStatus::Accepted->value)->update(['status' => DB::raw('status')]);
        if ($changed !== 1) {
            throw new ReservationFailure('La solicitud ya no está aceptada. Consulta su estado actual.');
        }
    }

    private function setting(ServiceInquiry $inquiry): ProviderAvailabilitySetting
    {
        $this->lockParents($inquiry);
        if (! Service::publiclyVisible()->whereKey($inquiry->service_id)->exists()
            || ! $inquiry->client->hasPermission(Permission::ManageOwnInquiries)
            || ! $inquiry->service->providerProfile->user->hasPermission(Permission::ManageReceivedInquiries)) {
            throw new ReservationFailure('El servicio o sus participantes ya no permiten nuevas reservas.');
        }
        $setting = ReservationTransactions::current($inquiry->service->providerProfile->availabilitySetting()->getQuery())->first();
        if (! $setting) {
            throw new ReservationFailure('El prestador no ha publicado horarios. No es posible proponer ni confirmar una reserva.');
        }

        return $setting;
    }

    private function available(ServiceInquiry $inquiry, ProviderAvailabilitySetting $setting, string $date, array $slot): void
    {
        $now = $this->commandTime();
        $today = $now->copy()->setTimezone($setting->timezone)->startOfDay();
        if ($slot['starts_at_utc'] <= $now->format('Y-m-d H:i:s')) {
            throw new ReservationFailure('El horario propuesto ya pasó o está en curso. Elige un horario futuro.');
        }
        if ($date > $today->addDays(max(1, (int) config('reservations.horizon_days', 90)))->format('Y-m-d')) {
            throw ValidationException::withMessages(['date' => 'La fecha supera el horizonte de reservas permitido.']);
        }
        $profile = $inquiry->service->providerProfile;
        if (ReservationTransactions::current($profile->blockedDates()->where('blocked_date', $date)->getQuery())->exists()
            || ! ReservationTransactions::current($profile->weeklyIntervals()->where('day_of_week', $slot['weekday'])->where('start_minute', '<=', $slot['local_start_minute'])
                ->where('end_minute', '>=', $slot['local_start_minute'] + 60)->getQuery())->exists()) {
            throw new ReservationFailure('El horario no está dentro de la disponibilidad vigente o la fecha está bloqueada.');
        }
        $occupancy = ReservationTransactions::writingMysql()
            ? ReservationTransactions::activeRows(occupancy: true)->where('current_booking.provider_profile_id', $profile->id)
            : DB::table('reservation_occupancy')->where('provider_profile_id', $profile->id);
        if ($occupancy->where('starts_at_utc', '<', $slot['ends_at_utc'])->where('ends_at_utc', '>', $slot['starts_at_utc'])->exists()) {
            throw new ReservationFailure('El horario ya no está disponible. Propón otro horario.');
        }
    }

    private function lockParents(ServiceInquiry $inquiry): void
    {
        if (ReservationTransactions::writingMysql()) {
            $service = Service::whereKey($inquiry->service_id)->sharedLock()->firstOrFail();
            $profile = ProviderProfile::whereKey($service->provider_profile_id)->lockForUpdate()->firstOrFail();
            $service->setRelation('providerProfile', $profile);
            $inquiry->setRelation('service', $service);
        }
    }

    private function commandTime(): \Carbon\CarbonInterface
    {
        return ReservationTransactions::writingMysql() ? BookingClock::date(BookingClock::micros()) : now('UTC');
    }

    private function transition(ServiceReservation $reservation, ReservationStatus $target, User $actor, string $reason): void
    {
        $changed = ServiceReservation::whereKey($reservation->id)->where('status', $reservation->status->value)->where('version', $reservation->version)->update([
            'status' => $target->value, 'version' => $reservation->version + 1, 'last_actor_user_id' => $actor->id,
            'last_reason' => $reason, 'updated_at' => $this->commandTime()->format('Y-m-d H:i:s')]);
        if ($changed !== 1) {
            throw new ReservationFailure;
        }
    }

    private function safely(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (\PDOException $exception) {
            // No conservar SQL ni bindings en una excepción previa; los triggers también terminan aquí.
            $code = $exception->errorInfo[1] ?? null;
            $conflict = DB::connection()->getDriverName() === 'mysql'
                ? in_array($code, [1062, 1205, 1213], true)
                    || ($code === 1644 && in_array($exception->errorInfo[2] ?? null, [
                        'reservation_transition', 'reservation_hold_transition', 'reservation_overlap',
                        'reservation_inquiry_closed', 'reservation_one_active_inquiry', 'reservation_active_inquiry',
                        'reservation_accepted_quote_required', 'reservation_hold_identity',
                    ], true))
                : (is_int($code) && in_array($code & 255, [5, 6, 19], true));
            throw new ReservationFailure($conflict ? 'Hay un conflicto con el estado o el horario. Consulta Mis reservas antes de reintentar.' : 'No se pudo consultar o guardar la reserva. Inténtalo de nuevo más tarde.', $conflict ? 409 : 503, $exception);
        }
    }
}
