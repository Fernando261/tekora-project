<?php

namespace App\Services;

use App\Enums\InquiryAction;
use App\Enums\InquiryStatus;
use App\Exceptions\InquiryConflict;
use App\Models\Service;
use App\Models\ServiceInquiry;
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

                return $inquiry->fresh();
            }, 3);
        } catch (QueryException $exception) {
            if (! $this->causedByConcurrencyError($exception)) throw $exception;
            throw new InquiryConflict($inquiryId, 'Hay otra decisión en curso. Consulta el estado actual e inténtalo de nuevo.');
        }
    }
}
