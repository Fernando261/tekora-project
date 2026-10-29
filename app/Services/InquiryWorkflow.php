<?php

namespace App\Services;

use App\Enums\InquiryAction;
use App\Enums\InquiryStatus;
use App\Enums\InquiryNotificationEvent;
use App\Exceptions\InquiryConflict;
use App\Models\Service;
use App\Models\ServiceInquiry;
use App\Models\InquiryNotification;
use App\Models\User;
use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class InquiryWorkflow
{
    use DetectsConcurrencyErrors;

    /** @return array{inquiry: ServiceInquiry, created: bool} */
    public function open(User $client, int $serviceId, string $message): array
    {
        try {
            return DB::transaction(function () use ($client, $serviceId, $message) {
                $service = Service::publiclyVisible()->whereKey($serviceId)->lockForUpdate()->firstOrFail();
                Gate::forUser($client)->authorize('create', [ServiceInquiry::class, $service]);
                $existing = ServiceInquiry::where('service_id', $serviceId)->where('client_user_id', $client->id)->active()->first();
                if ($existing) return ['inquiry' => $existing, 'created' => false];

                $inquiry = new ServiceInquiry(['message' => $message]);
                $inquiry->service()->associate($service);
                $inquiry->client()->associate($client);
                $inquiry->service_title = $service->title;
                $inquiry->status = InquiryStatus::Pending;
                $inquiry->save();
                $this->notify($inquiry, InquiryNotificationEvent::Created, $client);

                return ['inquiry' => $inquiry, 'created' => true];
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            // La restricción de base de datos también protege dos altas que hayan leído a la vez.
            $existing = ServiceInquiry::where('service_id', $serviceId)->where('client_user_id', $client->id)->active()->first();
            if ($existing) return ['inquiry' => $existing, 'created' => false];
            throw new InquiryConflict(null);
        } catch (QueryException $exception) {
            if (! $this->causedByConcurrencyError($exception)) throw $exception;
            throw new InquiryConflict(null, 'Hay otra operación en curso. Revisa Mis solicitudes antes de volver a enviar.');
        }
    }

    public function decide(User $actor, int $inquiryId, InquiryAction $action): ServiceInquiry
    {
        try {
            return DB::transaction(function () use ($actor, $inquiryId, $action) {
                $inquiry = ServiceInquiry::findOrFail($inquiryId);
                Gate::forUser($actor)->authorize($action->value, $inquiry);
                $now = now();
                // Compare-and-swap: una sola decisión puede consumir el estado de origen.
                $changed = ServiceInquiry::whereKey($inquiryId)->where('status', $action->source()->value)->update([
                    'status' => $action->target()->value, $action->timestampColumn() => $now, 'updated_at' => $now,
                ]);
                if ($changed !== 1) throw new InquiryConflict($inquiryId);

                $updated = $inquiry->fresh();
                $this->notify($updated, InquiryNotificationEvent::from($action->target()->value), $actor);

                return $updated;
            }, 3);
        } catch (QueryException $exception) {
            if (! $this->causedByConcurrencyError($exception)) throw $exception;
            throw new InquiryConflict($inquiryId, 'Hay otra decisión en curso. Consulta el estado actual e inténtalo de nuevo.');
        }
    }

    private function notify(ServiceInquiry $inquiry, InquiryNotificationEvent $event, User $actor): void
    {
        $recipientId = $event->forProvider() ? $inquiry->service->providerProfile->user_id : $inquiry->client_user_id;
        if ($recipientId === $actor->id) return;

        // Misma conexión y transacción que la operación. Si falla el aviso, también se revierte la solicitud.
        $notification = new InquiryNotification;
        $notification->setConnection($inquiry->getConnectionName());
        $notification->user_id = $recipientId;
        $notification->service_inquiry_id = $inquiry->id;
        $notification->event = $event;
        $notification->save();
    }
}
