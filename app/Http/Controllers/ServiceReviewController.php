<?php

namespace App\Http\Controllers;

use App\Enums\ReservationStatus;
use App\Exceptions\ReviewFailure;
use App\Models\ServiceReservation;
use App\Models\ServiceReview;
use App\Services\ReviewWorkflow;
use App\Support\ReviewInput;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ServiceReviewController extends Controller
{
    use \Illuminate\Database\DetectsConcurrencyErrors;

    public function index(Request $request)
    {
        return $this->safely($request, function () use ($request) {
            Gate::forUser($request->user('web'))->authorize('viewAny', ServiceReview::class);
            $page = ReviewInput::page($request);
            $reviews = ServiceReview::where('client_user_id', $request->user('web')->id)->with('service:id,title')
                ->orderByDesc('id')->paginate(12, ['*'], 'page', $page);
            if ($page > $reviews->lastPage()) throw new ReviewFailure('La página de reseñas no existe.', 404);

            return response()->view('reviews.index', compact('reviews'));
        });
    }

    public function create(Request $request, int $reservation)
    {
        return $this->safely($request, function () use ($request, $reservation) {
            return DB::transaction(function () use ($request, $reservation) {
                $reservation = ServiceReservation::findOrFail($reservation);
                Gate::forUser($request->user('web'))->authorize('create', [ServiceReview::class, $reservation]);
                if ($reservation->status !== ReservationStatus::Completed) throw new ReviewFailure('Solo puedes reseñar una reserva completada.');
                $existing = $reservation->review()->first();
                if ($existing) return redirect()->route('reviews.show', $existing)->with('info', 'Esta reserva ya tiene una reseña. Se conserva su registro original.');
                $reservation->load('service:id,title');

                return response()->view('reviews.create', compact('reservation'));
            }, 3);
        });
    }

    public function store(Request $request, int $reservation, ReviewWorkflow $workflow)
    {
        return $this->safely($request, function () use ($request, $reservation, $workflow) {
            $source = ServiceReservation::findOrFail($reservation);
            Gate::forUser($request->user('web'))->authorize('create', [ServiceReview::class, $source]);
            $result = $workflow->publish($request->user('web'), $reservation, $request->except('_token', '_method'));

            return redirect()->route('reviews.show', $result['review'], 303)->with($result['created'] ? 'success' : 'info',
                $result['created'] ? 'Reseña publicada. Puedes consultar su estado y retirarla de la presentación pública.' : 'Se conserva la reseña original. No se creó otra.');
        });
    }

    public function show(Request $request, int $review)
    {
        return $this->safely($request, function () use ($request, $review) {
            $review = ServiceReview::findOrFail($review);
            Gate::forUser($request->user('web'))->authorize('view', $review);
            $review->load('service:id,title');

            return response()->view('reviews.show', compact('review'));
        });
    }

    public function withdraw(Request $request, int $review, ReviewWorkflow $workflow)
    {
        return $this->safely($request, function () use ($request, $review, $workflow) {
            $source = ServiceReview::findOrFail($review);
            Gate::forUser($request->user('web'))->authorize('withdraw', $source);
            $updated = $workflow->withdraw($request->user('web'), $review, $request->except('_token', '_method'));

            return redirect()->route('reviews.show', $updated, 303)->with('success', 'Reseña retirada. Se conserva tu registro privado y ya no contribuye a las valoraciones públicas.');
        });
    }

    private function safely(Request $request, callable $operation)
    {
        try { return $operation(); }
        catch (ReviewFailure $exception) { return $exception->render($request); }
        catch (\Illuminate\Auth\Access\AuthorizationException $exception) { return (new ReviewFailure('No tienes permiso para consultar o modificar esta reseña.', 403))->render($request); }
        catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) { return (new ReviewFailure('La reseña o reserva no se encontró.', 404))->render($request); }
        catch (\Throwable $exception) {
            for ($cause = $exception; $cause; $cause = $cause->getPrevious()) {
                if ($cause instanceof \PDOException) {
                    if (! $request->isMethodSafe() && $this->causedByConcurrencyError($cause)) return (new ReviewFailure)->render($request);

                    return (new ReviewFailure('Las reseñas no están disponibles temporalmente. Inténtalo más tarde.', 503))->render($request);
                }
            }
            throw $exception;
        }
    }
}
