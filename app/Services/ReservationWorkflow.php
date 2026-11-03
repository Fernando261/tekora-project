<?php

namespace App\Services;

use App\Enums\InquiryStatus;
use App\Enums\Permission;
use App\Enums\ReservationStatus;
use App\Exceptions\InquiryConflict;
use App\Exceptions\ReservationFailure;
use App\Models\ProviderAvailabilitySetting;
use App\Models\Service;
use App\Models\ServiceInquiry;
use App\Models\ServiceReservation;
use App\Models\User;
use App\Support\ReservationInput;
use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ReservationWorkflow
{
    use DetectsConcurrencyErrors;

    /** @return array{reservation: ServiceReservation, created: bool} */
    public function propose(User $actor, int $inquiryId, array $input): array
    {
        return $this->safely(function () use ($actor, $inquiryId, $input) {
            return DB::transaction(function () use ($actor, $inquiryId, $input) {
                $inquiry = ServiceInquiry::findOrFail($inquiryId);
                Gate::forUser($actor)->authorize('propose', [ServiceReservation::class, $inquiry]);
                $data = ReservationInput::proposal($input);
                $key = strtolower($data['idempotency_key']);
                $existing = ServiceReservation::where('client_user_id', $actor->id)->where('service_inquiry_id', $inquiryId)->where('idempotency_key', $key)->first();
                if ($existing) {
                    if ($existing->local_date !== $data['date'] || $existing->localTime() !== $data['time'] || $existing->availability_revision !== (int) $data['availability_revision']) {
                        throw new ReservationFailure('Este identificador ya se usó para otra propuesta. Abre un formulario nuevo.');
                    }

                    return ['reservation' => $existing, 'created' => false];
                }
                $this->lockInquiry($inquiryId);
                $inquiry = $inquiry->fresh();
                Gate::forUser($actor)->authorize('propose', [ServiceReservation::class, $inquiry]);
                if ($inquiry->reservations()->active()->exists()) throw new ReservationFailure('Esta solicitud ya tiene una propuesta o reserva activa. Consulta su estado.');
                $setting = $this->setting($inquiry);
                if ($setting->revision !== (int) $data['availability_revision']) throw new ReservationFailure('La disponibilidad cambió. Abre un formulario nuevo para consultar la zona y los horarios actuales.');
                $slot = app(ReservationTime::class)->resolve($data['date'], $data['time'], $setting->timezone);
                $this->available($inquiry, $setting, $data['date'], $slot);

                $reservation = new ServiceReservation;
                $reservation->service_inquiry_id = $inquiry->id; $reservation->service_id = $inquiry->service_id;
                $reservation->client_user_id = $inquiry->client_user_id; $reservation->provider_profile_id = $inquiry->service->provider_profile_id;
                $reservation->timezone = $setting->timezone; $reservation->local_date = $data['date'];
                $reservation->local_start_minute = $slot['local_start_minute']; $reservation->starts_at_utc = $slot['starts_at_utc']; $reservation->ends_at_utc = $slot['ends_at_utc'];
                $reservation->availability_revision = $setting->revision; $reservation->idempotency_key = $key;
                $reservation->status = ReservationStatus::Requested; $reservation->version = 1;
                $reservation->last_actor_user_id = $actor->id; $reservation->last_reason = 'proposal';
                $reservation->created_at = $reservation->updated_at = now('UTC')->format('Y-m-d H:i:s');
                $reservation->save();

                return ['reservation' => $reservation->fresh(), 'created' => true];
            }, 3);
        });
    }

    public function decide(User $actor, int $reservationId, string $action, array $input): ServiceReservation
    {
        return $this->safely(function () use ($actor, $reservationId, $action, $input) {
            return DB::transaction(function () use ($actor, $reservationId, $action, $input) {
                abort_unless(in_array($action, ['confirm', 'reject', 'cancel', 'complete'], true), 404);
                $reservation = ServiceReservation::findOrFail($reservationId);
                Gate::forUser($actor)->authorize($action, $reservation);
                $data = ReservationInput::decision($input);
                $this->lockInquiry($reservation->service_inquiry_id);
                $reservation = $reservation->fresh();
                Gate::forUser($actor)->authorize($action, $reservation);
                if ($reservation->version !== (int) $data['version']) throw new ReservationFailure;
                $now = now('UTC')->format('Y-m-d H:i:s');
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
                if (! $allowed) throw new ReservationFailure('La propuesta expiró, ya fue decidida o la acción no corresponde a su estado y horario actuales.');
                if ($action === 'confirm') {
                    $inquiry = $reservation->inquiry;
                    $setting = $this->setting($inquiry);
                    if ($setting->timezone !== $reservation->timezone) throw new ReservationFailure('La zona horaria cambió desde la propuesta. Recházala o cancélala y prepara una nueva.');
                    $slot = app(ReservationTime::class)->resolve($reservation->local_date, $reservation->localTime(), $setting->timezone);
                    if ($slot['starts_at_utc'] !== $reservation->starts_at_utc || $slot['ends_at_utc'] !== $reservation->ends_at_utc) throw new ReservationFailure('Las reglas de la zona horaria cambiaron. Prepara una propuesta nueva.');
                    $this->available($inquiry, $setting, $reservation->local_date, $slot);
                }
                $this->transition($reservation, $target, $actor, $action);

                return $reservation->fresh();
            }, 3);
        });
    }

    // Ejecutado por InquiryWorkflow dentro de su misma transacción, antes de cerrar la solicitud.
    public function closeForInquiry(ServiceInquiry $inquiry, User $actor): void
    {
        $this->safely(function () use ($inquiry, $actor) {
            if (DB::transactionLevel() < 1) throw new \LogicException('El cierre conjunto requiere la transacción de InquiryWorkflow.');
            $this->lockInquiry($inquiry->id);
            Gate::forUser($actor)->authorize('complete', $inquiry->fresh());
            $active = $inquiry->reservations()->active()->first();
            if (! $active) return;
            if ($active->status === ReservationStatus::Confirmed && $active->ends_at_utc > now('UTC')->format('Y-m-d H:i:s')) {
                throw new InquiryConflict($inquiry->id, 'Hay una reserva confirmada que todavía no termina. Cancélala antes de su inicio o espera a que termine para finalizar la solicitud.');
            }
            $this->transition($active, $active->status === ReservationStatus::Confirmed ? ReservationStatus::Completed : ReservationStatus::Cancelled, $actor, 'inquiry_completed');
        });
    }

    private function lockInquiry(int $id): void
    {
        // SQLite ignora FOR UPDATE: esta escritura condicional obtiene su bloqueo real, compartido con decisiones y mensajes.
        $changed = ServiceInquiry::whereKey($id)->where('status', InquiryStatus::Accepted->value)->update(['status' => DB::raw('status')]);
        if ($changed !== 1) throw new ReservationFailure('La solicitud ya no está aceptada. Consulta su estado actual.');
    }

    private function setting(ServiceInquiry $inquiry): ProviderAvailabilitySetting
    {
        if (! Service::publiclyVisible()->whereKey($inquiry->service_id)->exists()
            || ! $inquiry->client->hasPermission(Permission::ManageOwnInquiries)
            || ! $inquiry->service->providerProfile->user->hasPermission(Permission::ManageReceivedInquiries)) {
            throw new ReservationFailure('El servicio o sus participantes ya no permiten nuevas reservas.');
        }
        $setting = $inquiry->service->providerProfile->availabilitySetting()->first();
        if (! $setting) throw new ReservationFailure('El prestador no ha publicado horarios. No es posible proponer ni confirmar una reserva.');

        return $setting;
    }

    private function available(ServiceInquiry $inquiry, ProviderAvailabilitySetting $setting, string $date, array $slot): void
    {
        $now = now('UTC'); $today = $now->copy()->setTimezone($setting->timezone)->startOfDay();
        if ($slot['starts_at_utc'] <= $now->format('Y-m-d H:i:s')) throw new ReservationFailure('El horario propuesto ya pasó o está en curso. Elige un horario futuro.');
        if ($date > $today->addDays(max(1, (int) config('reservations.horizon_days', 90)))->format('Y-m-d')) {
            throw ValidationException::withMessages(['date' => 'La fecha supera el horizonte de reservas permitido.']);
        }
        $profile = $inquiry->service->providerProfile;
        if ($profile->blockedDates()->where('blocked_date', $date)->exists()
            || ! $profile->weeklyIntervals()->where('day_of_week', $slot['weekday'])->where('start_minute', '<=', $slot['local_start_minute'])
                ->where('end_minute', '>=', $slot['local_start_minute'] + 60)->exists()) {
            throw new ReservationFailure('El horario no está dentro de la disponibilidad vigente o la fecha está bloqueada.');
        }
        if (ServiceReservation::where('provider_profile_id', $profile->id)->where('status', ReservationStatus::Confirmed->value)
            ->where('starts_at_utc', '<', $slot['ends_at_utc'])->where('ends_at_utc', '>', $slot['starts_at_utc'])->exists()) {
            throw new ReservationFailure('El horario ya no está disponible. Propón otro horario.');
        }
    }

    private function transition(ServiceReservation $reservation, ReservationStatus $target, User $actor, string $reason): void
    {
        $changed = ServiceReservation::whereKey($reservation->id)->where('status', $reservation->status->value)->where('version', $reservation->version)->update([
            'status' => $target->value, 'version' => $reservation->version + 1, 'last_actor_user_id' => $actor->id,
            'last_reason' => $reason, 'updated_at' => now('UTC')->format('Y-m-d H:i:s')]);
        if ($changed !== 1) throw new ReservationFailure;
    }

    private function safely(callable $operation): mixed
    {
        try { return $operation(); }
        catch (\PDOException $exception) {
            // No conservar SQL ni bindings en una excepción previa; los triggers también terminan aquí.
            $conflict = $this->causedByConcurrencyError($exception) || str_contains($exception->getMessage(), 'constraint') || str_contains($exception->getMessage(), 'reservation_');
            throw new ReservationFailure($conflict ? 'Hay un conflicto con el estado o el horario. Consulta Mis reservas antes de reintentar.' : 'No se pudo consultar o guardar la reserva. Inténtalo de nuevo más tarde.', $conflict ? 409 : 503);
        }
    }
}
