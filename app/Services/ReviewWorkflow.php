<?php

namespace App\Services;

use App\Enums\ReservationStatus;
use App\Enums\ReviewVisibility;
use App\Exceptions\ReviewFailure;
use App\Models\ServiceReservation;
use App\Models\ServiceReview;
use App\Models\User;
use App\Support\ReviewInput;
use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ReviewWorkflow
{
    use DetectsConcurrencyErrors;

    /** @return array{review: ServiceReview, created: bool} */
    public function publish(User $actor, int $reservationId, array $input): array
    {
        return $this->safely(function () use ($actor, $reservationId, $input) {
            $operation = function (bool $existingOnly = false) use ($actor, $reservationId, $input) {
                $reservation = ServiceReservation::findOrFail($reservationId);
                Gate::forUser($actor)->authorize('create', [ServiceReview::class, $reservation]);
                if ($reservation->status !== ReservationStatus::Completed) throw new ReviewFailure('Solo puedes reseñar una reserva completada.');
                $data = ReviewInput::publish($input);
                $existing = ServiceReview::where('service_reservation_id', $reservationId)->first();
                if ($existing) return $this->replay($existing, $data);
                if ($existingOnly) throw new ReviewFailure;

                $review = new ServiceReview;
                $review->service_reservation_id = $reservation->id; $review->service_id = $reservation->service_id;
                $review->provider_profile_id = $reservation->provider_profile_id; $review->client_user_id = $reservation->client_user_id;
                $review->rating = $data['rating']; $review->comment = $data['comment']; $review->status = ReviewVisibility::Published;
                $review->created_at = now('UTC')->format('Y-m-d H:i:s');
                // El INSERT obtiene el bloqueo de escritura; UNIQUE y los triggers son la garantía final.
                // No se escribe en reservas ni eventos para obtener un bloqueo artificial.
                $review->save();
                Gate::forUser($actor)->authorize('create', [ServiceReview::class, $reservation->fresh()]);

                return ['review' => $review->fresh(), 'created' => true];
            };
            try { return DB::transaction(fn () => $operation(), 3); }
            catch (UniqueConstraintViolationException $exception) {
                // Tras el rollback, volver a autorizar y reconocer únicamente el mismo contenido.
                return DB::transaction(fn () => $operation(true), 3);
            }
        });
    }

    public function withdraw(User $actor, int $reviewId, array $input = []): ServiceReview
    {
        return $this->safely(function () use ($actor, $reviewId, $input) {
            return DB::transaction(function () use ($actor, $reviewId, $input) {
                $review = ServiceReview::findOrFail($reviewId);
                Gate::forUser($actor)->authorize('withdraw', $review);
                ReviewInput::only($input);
                if ($review->status === ReviewVisibility::Withdrawn) return $review;
                ServiceReview::whereKey($reviewId)->where('status', ReviewVisibility::Published->value)->update([
                    'status' => ReviewVisibility::Withdrawn->value, 'withdrawn_at_utc' => now('UTC')->format('Y-m-d H:i:s'), 'withdrawn_by_user_id' => $actor->id]);
                $updated = $review->fresh();
                Gate::forUser($actor)->authorize('withdraw', $updated);

                return $updated;
            }, 3);
        });
    }

    private function replay(ServiceReview $review, array $data): array
    {
        if ($review->rating !== $data['rating'] || $review->comment !== $data['comment']) {
            throw new ReviewFailure('Esta reserva ya tiene una reseña. No se puede cambiar ni reemplazar su calificación o comentario.');
        }

        return ['review' => $review, 'created' => false];
    }

    private function safely(callable $operation): mixed
    {
        try { return $operation(); }
        catch (\PDOException $exception) {
            // Usar códigos del motor, nunca nombres de conexión, SQL o comentario del usuario.
            $sqliteCode = ((int) ($exception->errorInfo[1] ?? 0)) & 255;
            $conflict = in_array($sqliteCode, [5, 6, 19], true) || $this->causedByConcurrencyError($exception);
            throw new ReviewFailure($conflict ? 'Hay un conflicto con el envío. Consulta Mis reseñas antes de reintentar.' : 'Las reseñas no están disponibles temporalmente. Inténtalo más tarde.', $conflict ? 409 : 503);
        }
    }
}
