<?php

namespace App\Services;

use App\Enums\InquiryNotificationEvent;
use App\Enums\Permission;
use App\Enums\RoleName;
use App\Exceptions\NotificationFailure;
use App\Models\ActivityNotification;
use App\Models\Service;
use App\Models\ServiceInquiry;
use App\Models\ServiceReservation;
use App\Models\User;
use App\Support\NotificationEntry;
use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class NotificationInbox
{
    use DetectsConcurrencyErrors;

    private function rows(User $user, bool $unreadOnly = false, bool $clientOnly = false): Builder
    {
        $inquiries = DB::table('inquiry_notifications')->where('user_id', $user->id);
        $activity = DB::table('activity_notifications')->where('user_id', $user->id);
        if ($clientOnly) {
            $scope = app(MobileClientOperations::class);
            $clientEvents = array_map(fn ($event) => $event->value, array_filter(InquiryNotificationEvent::cases(), fn ($event) => ! $event->forProvider()));
            $inquiries->whereIn('event', $clientEvents)
                ->whereIn('service_inquiry_id', $scope->inquiries($user)->select('service_inquiries.id'));
            // Review and requested notifications belong to the provider experience.
            $activity->whereIn('event', ['reservation_confirmed', 'reservation_rejected', 'reservation_cancelled', 'reservation_completed'])
                ->whereNull('service_review_id')
                ->whereIn('service_reservation_id', $scope->reservations($user)->select('service_reservations.id'));
        }
        if ($unreadOnly) {
            $inquiries->whereNull('read_at');
            $activity->whereNull('read_at');
        }
        $inquiries->selectRaw("id, 'inquiry' as source_type, event, created_at, read_at, service_inquiry_id, NULL as service_reservation_id, NULL as service_review_id");
        $activity->selectRaw("id, 'activity' as source_type, event, created_at, read_at, NULL as service_inquiry_id, service_reservation_id, service_review_id");

        $feed = DB::query()->fromSub($inquiries->unionAll($activity), 'notification_feed');
        if ($clientOnly) {
            // Role is checked in this same SQL statement, before count/order/pagination.
            $feed->whereExists(fn ($query) => $query->selectRaw('1')->from('role_user')
                ->join('roles', 'roles.id', '=', 'role_user.role_id')
                ->where('role_user.user_id', $user->id)->where('roles.slug', RoleName::Client->value));
        }

        return $feed;
    }

    public function clientListing(User $user, int $page = 1, int $perPage = 12)
    {
        return DB::transaction(function () use ($user, $page, $perPage) {
            $feed = $this->rows($user, clientOnly: true)->orderByDesc('created_at')->orderByDesc('source_type')->orderByDesc('id')
                ->paginate($perPage, ['*'], 'page', $page);
            if ($page > $feed->lastPage()) {
                throw new NotificationFailure('La página de notificaciones no existe.', 404);
            }

            return $feed;
        }, 3);
    }

    public function clientUnreadCount(User $user): int
    {
        // Unlike the web menu's nullable fallback, failures must propagate to JSON 503.
        return $this->rows($user, unreadOnly: true, clientOnly: true)->count();
    }

    public function clientNotice(User $user, string $source, string $id): object
    {
        abort_unless(in_array($source, ['inquiry', 'activity'], true), 404);
        $notice = $this->rows($user, clientOnly: true)->where('source_type', $source)->where('id', $id)->first();
        abort_unless($notice, 404);

        return $notice;
    }

    public function readClient(User $user, string $source, string $id): object
    {
        try {
            return DB::transaction(function () use ($user, $source, $id) {
                $notice = $this->clientNotice($user, $source, $id);
                // Same conditional read_at transition as the web; never overwrite first read.
                $table = $source === 'inquiry' ? 'inquiry_notifications' : 'activity_notifications';
                DB::table($table)->where('user_id', $user->id)->where('id', $notice->id)->whereNull('read_at')
                    ->update(['read_at' => now('UTC')->format('Y-m-d H:i:s')]);

                return $this->clientNotice($user, $source, $id);
            }, 3);
        } catch (\PDOException $exception) {
            $code = ((int) ($exception->errorInfo[1] ?? 0)) & 255;
            $conflict = in_array($code, [5, 6, 19], true) || $this->causedByConcurrencyError($exception);
            throw new NotificationFailure(status: $conflict ? 409 : 503);
        }
    }

    public function unreadCount(User $user): ?int
    {
        try {
            return $this->rows($user, true)->count();
        } catch (\PDOException $exception) {
            // El menú puede renderizarse durante un bloqueo o un error; no fingir cero ni lanzar SQL desde Blade.
            return null;
        }
    }

    public function listing(User $user, int $page = 1)
    {
        $notifications = $this->rows($user)->orderByDesc('created_at')->orderByDesc('source_type')->orderByDesc('id')->paginate(12, ['*'], 'page', $page);
        if ($page > $notifications->lastPage()) {
            throw new NotificationFailure('La página de notificaciones no existe.', 404);
        }
        $rows = $notifications->getCollection();
        if ($rows->isEmpty()) {
            return $notifications;
        }
        $client = $user->hasPermission(Permission::ManageOwnInquiries);
        $provider = $user->hasPermission(Permission::ManageReceivedInquiries);

        // Equivalentes por lotes de las policies de solicitudes y reservas: roles vigentes + propiedad.
        // Los controladores de destino conservan su autorización canónica al abrir el enlace.
        $inquiryIds = $rows->pluck('service_inquiry_id')->filter()->unique();
        $inquiries = $inquiryIds->isEmpty() ? collect() : ServiceInquiry::whereIn('id', $inquiryIds)->where(function ($query) use ($user, $client, $provider) {
            $query->whereRaw('0 = 1');
            if ($client) {
                $query->orWhere('client_user_id', $user->id);
            }
            if ($provider) {
                $query->orWhereHas('service.providerProfile', fn ($profiles) => $profiles->where('user_id', $user->id));
            }
        })->pluck('id')->flip();
        $reservationIds = $rows->pluck('service_reservation_id')->filter()->unique();
        $reservations = $reservationIds->isEmpty() ? collect() : ServiceReservation::whereIn('id', $reservationIds)->where(function ($query) use ($user, $client, $provider) {
            $query->whereRaw('0 = 1');
            if ($client) {
                $query->orWhere('client_user_id', $user->id);
            }
            if ($provider) {
                $query->orWhereHas('providerProfile', fn ($profiles) => $profiles->where('user_id', $user->id));
            }
        })->pluck('id')->flip();
        $reviewIds = $rows->pluck('service_review_id')->filter()->unique();
        // Solo el ID del servicio público; nunca comentario, estrellas, autor o relación privada de la reseña.
        $reviewServices = $reviewIds->isEmpty() ? collect() : DB::table('service_reviews as reviews')
            ->joinSub(Service::publiclyVisible()->select('id'), 'visible_services', 'visible_services.id', '=', 'reviews.service_id')
            ->whereIn('reviews.id', $reviewIds)->pluck('reviews.service_id', 'reviews.id');

        return $notifications->through(function ($row) use ($inquiries, $reservations, $reviewServices) {
            $url = null;
            $label = '';
            $message = 'El detalle no está disponible con tus permisos actuales.';
            if ($row->source_type === 'inquiry') {
                if ($inquiries->has($row->service_inquiry_id)) {
                    $url = route('inquiries.show', $row->service_inquiry_id);
                    $label = 'Ver solicitud #'.$row->service_inquiry_id;
                }
            } elseif ($row->service_reservation_id !== null) {
                if ($reservations->has($row->service_reservation_id)) {
                    $url = route('reservations.show', $row->service_reservation_id);
                    $label = 'Ver reserva';
                }
            } else {
                $message = 'El servicio no está disponible públicamente.';
                if ($reviewServices->has($row->service_review_id)) {
                    $url = route('catalog.show', $reviewServices->get($row->service_review_id)).'#resenas';
                    $label = 'Ver servicio público';
                }
            }

            return new NotificationEntry($row, $url, $label, $message);
        });
    }

    public function readActivity(User $user, string $id, array $input = []): ActivityNotification
    {
        try {
            return DB::transaction(function () use ($user, $id, $input) {
                $notice = ActivityNotification::where('user_id', $user->id)->findOrFail($id);
                Gate::forUser($user)->authorize('read', $notice);
                if ($input !== []) {
                    throw ValidationException::withMessages(['form' => 'Utiliza únicamente el botón de lectura. No puedes cambiar destinatario, evento, recurso ni fechas.']);
                }
                ActivityNotification::whereKey($notice->id)->where('user_id', $user->id)->unread()->update(['read_at' => now('UTC')->format('Y-m-d H:i:s')]);

                return $notice->fresh();
            }, 3);
        } catch (\PDOException $exception) {
            $code = ((int) ($exception->errorInfo[1] ?? 0)) & 255;
            $conflict = in_array($code, [5, 6, 19], true) || $this->causedByConcurrencyError($exception);
            throw new NotificationFailure($conflict ? 'Hay otra operación de lectura en curso. Consulta la bandeja e inténtalo de nuevo.' : 'No se pudo registrar la lectura. Inténtalo más tarde.', $conflict ? 409 : 503);
        }
    }
}
