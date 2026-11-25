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
use Illuminate\Database\Query\JoinClause;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use PDOException;

class NotificationInbox
{
    use DetectsConcurrencyErrors;

    private function rows(User $user, bool $unreadOnly = false, bool $clientOnly = false): Builder
    {
        $inquiries = $this->sourceRows($user, 'inquiry', $clientOnly);
        $activity = $this->sourceRows($user, 'activity', $clientOnly);
        if ($unreadOnly) {
            $inquiries->whereNull('read_at');
            $activity->whereNull('read_at');
        }

        $feed = DB::query()->fromSub($inquiries->unionAll($activity), 'notification_feed');

        return $clientOnly ? $this->withClientRole($feed, $user) : $feed;
    }

    private function sourceRows(User $user, string $source, bool $clientOnly, bool $current = false): Builder
    {
        $inquiry = $source === 'inquiry';
        $table = $inquiry ? 'inquiry_notifications' : 'activity_notifications';
        $rows = DB::table($table)->where('user_id', $user->id);
        if ($clientOnly) {
            $scope = app(MobileClientOperations::class);
            $clientEvents = array_map(fn ($event) => $event->value, array_filter(InquiryNotificationEvent::cases(), fn ($event) => ! $event->forProvider()));
            if ($inquiry) {
                $rows->whereIn('event', $clientEvents);
                $resources = $scope->inquiries($user)->whereColumn('service_inquiries.id', 'inquiry_notifications.service_inquiry_id');
            } else {
                // Review and requested notifications belong to the provider experience.
                $rows->whereIn('event', ['reservation_confirmed', 'reservation_rejected', 'reservation_cancelled', 'reservation_completed'])
                    ->whereNull('service_review_id');
                $resources = $scope->reservations($user)->whereColumn('service_reservations.id', 'activity_notifications.service_reservation_id');
            }
            if ($current) {
                $resources->sharedLock();
            }
            $rows->whereExists($resources->selectRaw('1')->toBase());
        }

        $fields = 'event, created_at, read_at';
        if (DB::connection()->getDriverName() === 'mysql') {
            // Normalize ENUM and VARCHAR collations. The connection exposes TIMESTAMP in UTC.
            $fields = 'CAST(event AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_0900_bin AS event, '
                .'CAST(created_at AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_0900_bin AS created_at, '
                .'CAST(read_at AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_0900_bin AS read_at';
        }
        $references = $inquiry ? 'service_inquiry_id, NULL as service_reservation_id, NULL as service_review_id'
            : 'NULL as service_inquiry_id, service_reservation_id, service_review_id';

        return $rows->selectRaw("id, '{$source}' as source_type, {$fields}, {$references}");
    }

    private function withClientRole(Builder $rows, User $user, bool $current = false): Builder
    {
        $role = DB::table('role_user')->selectRaw('1')->join('roles', 'roles.id', '=', 'role_user.role_id')
            ->where('role_user.user_id', $user->id)->where('roles.slug', RoleName::Client->value);
        if ($current) {
            $role->sharedLock();
        }

        return $rows->whereExists($role);
    }

    public function clientListing(User $user, int $page = 1, int $perPage = 12)
    {
        return DB::transaction(fn () => $this->paginate($this->rows($user, clientOnly: true), $page, $perPage), 3);
    }

    private function paginate(Builder $feed, int $page, int $perPage): LengthAwarePaginator
    {
        $ordered = $feed->orderByDesc('created_at')->orderByDesc('source_type')->orderByDesc('id');
        if (DB::connection()->getDriverName() === 'mysql') {
            // The total and the bounded page use one statement snapshot, including empty pages.
            $total = DB::query()->fromSub((clone $feed)->reorder(), 'feed_count')->selectRaw('COUNT(*) as inbox_total');
            $rows = DB::query()->fromSub($total, 'feed_total')
                ->leftJoinSub($ordered->forPage($page, $perPage), 'feed_page', fn (JoinClause $join) => $join->whereRaw('1 = 1'))
                ->orderByDesc('feed_page.created_at')->orderByDesc('feed_page.source_type')->orderByDesc('feed_page.id')->get();
            $results = new LengthAwarePaginator($rows->whereNotNull('id')->values(), (int) $rows->first()->inbox_total,
                $perPage, $page, ['path' => LengthAwarePaginator::resolveCurrentPath()]);
        } else {
            $results = $ordered->paginate($perPage, ['*'], 'page', $page);
        }
        if ($page > $results->lastPage()) {
            throw new NotificationFailure('La página de notificaciones no existe.', 404);
        }

        return $results;
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
        $driver = DB::connection()->getDriverName();
        try {
            return DB::transaction(function () use ($user, $source, $id, $driver) {
                abort_unless(in_array($source, ['inquiry', 'activity'], true), 404);
                $current = $driver === 'mysql'
                    ? $this->withClientRole($this->sourceRows($user, $source, true, current: true), $user, current: true)->where('id', $id)->lockForUpdate()
                    : null;
                $notice = $current === null ? $this->clientNotice($user, $source, $id) : $current->first();
                abort_unless($notice, 404);
                // Same conditional read_at transition as the web; never overwrite first read.
                $table = $source === 'inquiry' ? 'inquiry_notifications' : 'activity_notifications';
                DB::table($table)->where('user_id', $user->id)->where('id', $notice->id)->whereNull('read_at')
                    ->update(['read_at' => now('UTC')->format('Y-m-d H:i:s')]);

                return $current === null ? $this->clientNotice($user, $source, $id) : $current->first();
            }, 3);
        } catch (PDOException $exception) {
            throw $this->readFailure($exception, $driver);
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
        $notifications = $this->paginate($this->rows($user), $page, 12);
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
        $driver = DB::connection()->getDriverName();
        try {
            return DB::transaction(function () use ($user, $id, $input, $driver) {
                $owned = ActivityNotification::where('user_id', $user->id);
                if ($driver === 'mysql') {
                    $owned->lockForUpdate();
                }
                $notice = $owned->findOrFail($id);
                Gate::forUser($user)->authorize('read', $notice);
                if ($input !== []) {
                    throw ValidationException::withMessages(['form' => 'Utiliza únicamente el botón de lectura. No puedes cambiar destinatario, evento, recurso ni fechas.']);
                }
                ActivityNotification::whereKey($notice->id)->where('user_id', $user->id)->unread()->update(['read_at' => now('UTC')->format('Y-m-d H:i:s')]);

                return $driver === 'mysql' ? $owned->findOrFail($id) : $notice->fresh();
            }, 3);
        } catch (PDOException $exception) {
            throw $this->readFailure($exception, $driver);
        }
    }

    private function readFailure(PDOException $exception, string $driver): NotificationFailure
    {
        $cause = $exception->getPrevious();
        $info = $exception->errorInfo ?? ($cause instanceof PDOException ? $cause->errorInfo : null) ?? [];
        $code = (int) ($info[1] ?? 0);
        $conflict = match ($driver) {
            'sqlite' => in_array($code & 255, [5, 6, 19], true) || $this->causedByConcurrencyError($exception),
            'mysql' => in_array($code, [1205, 1213, 3819], true)
                || ($code === 1644 && ($info[2] ?? null) === 'activity_immutable'),
            default => false,
        };

        return new NotificationFailure($conflict ? 'Hay otra operación de lectura en curso. Consulta la bandeja e inténtalo de nuevo.'
            : 'No se pudo registrar la lectura. Inténtalo más tarde.', $conflict ? 409 : 503);
    }
}
