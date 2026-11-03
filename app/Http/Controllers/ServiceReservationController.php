<?php

namespace App\Http\Controllers;

use App\Enums\InquiryStatus;
use App\Enums\Permission;
use App\Exceptions\ReservationFailure;
use App\Models\ServiceInquiry;
use App\Models\ServiceReservation;
use App\Services\ReservationWorkflow;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class ServiceReservationController extends Controller
{
    public function index(Request $request)
    {
        return $this->safely($request, function () use ($request) {
            $actor = $request->user('web');
            Gate::forUser($actor)->authorize('viewAny', ServiceReservation::class);
            $reservations = ServiceReservation::with(['inquiry:id,service_title', 'client:id,name', 'providerProfile:id,display_name'])
                ->where(function ($query) use ($actor) {
                    $query->whereRaw('0 = 1');
                    if ($actor->hasPermission(Permission::ManageOwnInquiries)) $query->orWhere('client_user_id', $actor->id);
                    if ($actor->hasPermission(Permission::ManageReceivedInquiries)) $query->orWhereHas('providerProfile', fn ($q) => $q->where('user_id', $actor->id));
                })->orderByDesc('id')->paginate(12);

            return response()->view('reservations.index', compact('reservations'));
        });
    }

    public function create(Request $request, int $inquiry)
    {
        return $this->safely($request, function () use ($request, $inquiry) {
            return DB::transaction(function () use ($request, $inquiry) {
                $inquiry = ServiceInquiry::with(['service.providerProfile', 'client'])->findOrFail($inquiry);
                Gate::forUser($request->user('web'))->authorize('propose', [ServiceReservation::class, $inquiry]);
                if ($inquiry->status !== InquiryStatus::Accepted) throw new ReservationFailure('Solo una solicitud aceptada permite proponer una reserva.');
                $active = $inquiry->reservations()->active()->first();
                if ($active) return redirect()->route('reservations.show', $active)->with('info', 'Esta solicitud ya tiene una propuesta o reserva activa.');
                $profile = $inquiry->service->providerProfile;
                $setting = $profile->availabilitySetting()->first();
                $schedule = app(\App\Services\ProviderAvailability::class)->publicFor($profile);
                if (! $setting || ! $schedule || isset($schedule['unavailable']) || ! $profile->weeklyIntervals()->exists()) {
                    throw new ReservationFailure('El prestador no tiene horarios publicados para nuevas propuestas.');
                }
                $minDate = now($setting->timezone)->format('Y-m-d');
                $maxDate = now($setting->timezone)->addDays(max(1, (int) config('reservations.horizon_days', 90)))->format('Y-m-d');
                $key = (string) Str::uuid();

                return response()->view('reservations.create', compact('inquiry', 'setting', 'schedule', 'minDate', 'maxDate', 'key'));
            }, 3);
        });
    }

    public function store(Request $request, int $inquiry, ReservationWorkflow $workflow)
    {
        return $this->safely($request, function () use ($request, $inquiry, $workflow) {
            $result = $workflow->propose($request->user('web'), $inquiry, $request->except('_token', '_method'));

            return redirect()->route('reservations.show', $result['reservation'], 303)->with($result['created'] ? 'success' : 'info',
                $result['created'] ? 'Horario propuesto. La reserva requiere confirmación explícita del prestador.' : 'Se conserva la propuesta original. No se creó otra reserva.');
        });
    }

    public function show(Request $request, int $reservation)
    {
        return $this->safely($request, function () use ($request, $reservation) {
            return DB::transaction(function () use ($request, $reservation) {
                $reservation = ServiceReservation::findOrFail($reservation);
                Gate::forUser($request->user('web'))->authorize('view', $reservation);
                $reservation->load(['inquiry:id,service_title,status', 'client:id,name', 'providerProfile:id,display_name', 'events.actor:id,name']);

                return response()->view('reservations.show', compact('reservation'));
            }, 3);
        });
    }

    public function decide(Request $request, int $reservation, ReservationWorkflow $workflow)
    {
        return $this->safely($request, function () use ($request, $reservation, $workflow) {
            $updated = $workflow->decide($request->user('web'), $reservation, $request->route()->defaults['decision'], $request->except('_token', '_method'));

            return redirect()->route('reservations.show', $updated, 303)->with('success', 'Estado actualizado: '.$updated->status->label().'.');
        });
    }

    private function safely(Request $request, callable $operation)
    {
        try { return $operation(); }
        catch (ReservationFailure $exception) { return $this->error($request, $exception); }
        catch (\Illuminate\Auth\Access\AuthorizationException $exception) { return $this->error($request, new ReservationFailure('No tienes permiso para consultar o modificar esta reserva.', 403)); }
        catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) { return $this->error($request, new ReservationFailure('La reserva o solicitud no se encontró.', 404)); }
        catch (\Throwable $exception) {
            // Blade envuelve fallos de consultas: tampoco deben escapar con SQL durante el render.
            for ($cause = $exception; $cause; $cause = $cause->getPrevious()) {
                if ($cause instanceof \PDOException) return $this->error($request, new ReservationFailure('No se pudieron consultar las reservas. Inténtalo de nuevo más tarde.', 503));
            }
            throw $exception;
        }
    }

    private function error(Request $request, ReservationFailure $exception)
    {
        $message = $exception->getMessage();
        if ($request->expectsJson()) return response()->json(['message' => $message], $exception->status);

        return response()->view('reservations.error', compact('message'), $exception->status);
    }
}
