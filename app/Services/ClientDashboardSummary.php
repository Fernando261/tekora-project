<?php

namespace App\Services;

use App\Enums\Permission;
use App\Exceptions\ClientDashboardFailure;
use App\Exceptions\ReservationFailure;
use App\Models\User;
use App\Support\BookingClock;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class ClientDashboardSummary
{
    public const TIMEZONE = 'America/Mexico_City';

    public const LIST_LIMIT = 5;

    public function build(User $user, array $filters = []): array
    {
        Gate::forUser($user)->authorize(Permission::ManageOwnInquiries->value);
        if ($filters !== []) {
            throw new ClientDashboardFailure('Este panel muestra la actividad de tu sesión. Accede sin filtros de identidad.', 422);
        }

        return $this->summarize($user, mobile: false);
    }

    public function forMobile(User $user): array
    {
        Gate::forUser($user)->authorize(Permission::ManageOwnInquiries->value);

        return DB::transaction(fn () => $this->summarize($user, mobile: true));
    }

    private function summarize(User $user, bool $mobile): array
    {
        // Establish the storage snapshot before sampling the booking clock.
        $observed = $mobile ? $this->bookingTime() : null;
        $now = $mobile ? BookingClock::date($observed) : CarbonImmutable::now('UTC')->startOfSecond();
        $instant = $now->format('Y-m-d H:i:s');
        $scope = app(MobileClientOperations::class);
        $inquiries = $scope->inquiries($user);
        $reservations = $scope->reservations($user);
        $active = (clone $inquiries)->active()->count();

        $holdClock = $mobile
            ? '?'
            : BookingClock::observedSql();
        $totals = (clone $reservations)
            ->leftJoin('reservation_holds as hold', 'hold.service_reservation_id', '=', 'service_reservations.id')
            ->selectRaw("COUNT(CASE WHEN status = 'confirmed' AND starts_at_utc > ? THEN 1 END) AS confirmed,
                COUNT(CASE WHEN status = 'requested' AND hold.service_reservation_id IS NULL AND starts_at_utc > ? THEN 1 END) AS requested,
                COUNT(CASE WHEN status = 'completed' THEN 1 END) AS completed,
                COUNT(CASE WHEN status = 'cancelled' THEN 1 END) AS cancelled,
                COUNT(CASE WHEN hold.state = 'held' AND hold.expires_at_us > {$holdClock} THEN 1 END) AS held,
                COUNT(CASE WHEN hold.state = 'held' AND hold.expires_at_us <= {$holdClock} THEN 1 END) AS expired_holds",
                $mobile ? [$instant, $instant, $observed, $observed] : [$instant, $instant])->first();
        $held = (int) $totals->held;
        if (! $mobile && (int) $totals->expired_holds > 0) {
            try {
                app(ReservationWorkflow::class)->refreshExpirations();
            } catch (ReservationFailure) {
                throw new ClientDashboardFailure('No se pudo comprobar el vencimiento de las retenciones.', 503);
            }

            return $this->summarize($user, mobile: false);
        }

        $unreadQuery = app(InquiryReadTracking::class)->withUnreadCount((clone $inquiries)->select('service_inquiries.id'), $user);
        $conversations = DB::query()->fromSub($unreadQuery->toBase(), 'client_conversations');
        $unread = $mobile
            ? $conversations->where('unread_messages_count', '>', 0)->count()
            : (int) $conversations->sum('unread_messages_count');

        $agenda = (clone $reservations)->where('service_reservations.status', 'confirmed')->where('starts_at_utc', '>', $instant)
            ->join('services', 'services.id', '=', 'service_reservations.service_id')->orderBy('starts_at_utc')->orderBy('service_reservations.id')
            ->limit(self::LIST_LIMIT)->get(['service_reservations.id', 'service_reservations.service_id', 'service_reservations.service_inquiry_id',
                'services.title', 'starts_at_utc', 'ends_at_utc'])
            ->map(function ($row) use ($mobile) {
                $start = CarbonImmutable::parse($row->starts_at_utc, 'UTC');
                $end = CarbonImmutable::parse($row->ends_at_utc, 'UTC');

                return $mobile
                    ? ['id' => $row->id, 'inquiry_id' => $row->service_inquiry_id, 'service_id' => $row->service_id, 'title' => $row->title,
                        'status' => 'confirmed', 'starts_at' => $start->toISOString(), 'ends_at' => $end->toISOString()]
                    : ['id' => $row->id, 'title' => $row->title, 'url' => route('reservations.show', $row->id), 'iso' => $start->toIso8601String(),
                        'date' => $start->setTimezone(self::TIMEZONE)->format('d/m/Y H:i'), 'end' => $end->setTimezone(self::TIMEZONE)->format('H:i')];
            })->all();
        $latest = (clone $inquiries)->join('services', 'services.id', '=', 'service_inquiries.service_id')
            ->orderByDesc('service_inquiries.created_at')->orderByDesc('service_inquiries.id')->limit(self::LIST_LIMIT)
            ->get(['service_inquiries.id', 'service_inquiries.service_id', 'services.title', 'service_inquiries.status', 'service_inquiries.created_at'])
            ->map(function ($row) use ($mobile) {
                $timestamp = $row->getRawOriginal('created_at');
                $created = $mobile ? $this->storedTimestamp($timestamp) : CarbonImmutable::parse($timestamp, 'UTC');

                return $mobile
                    ? ['id' => $row->id, 'service_id' => $row->service_id, 'title' => $row->title, 'status' => $row->status->value,
                        'created_at' => $created->toISOString()]
                    : ['id' => $row->id, 'title' => $row->title, 'status' => $row->status, 'url' => route('inquiries.show', $row->id),
                        'iso' => $created->toIso8601String(), 'date' => $created->setTimezone(self::TIMEZONE)->format('d/m/Y H:i')];
            })->all();

        $inbox = app(NotificationInbox::class);
        $counts = ['active' => $active, 'confirmed' => (int) $totals->confirmed, 'requested' => (int) $totals->requested,
            'completed' => (int) $totals->completed, 'cancelled' => (int) $totals->cancelled];
        if ($mobile) {
            return ['as_of' => $now->toISOString(), 'timezone' => self::TIMEZONE, 'counts' => $counts + [
                'held' => $held, 'unread_conversations' => $unread, 'unread_notifications' => $inbox->clientUnreadCount($user),
            ], 'upcoming_reservations' => $agenda, 'recent_inquiries' => $latest];
        }

        return ($held > 0 ? ['held' => $held] : []) + $counts + ['unread' => $unread,
            // The web counter intentionally describes the whole account.
            'notifications' => $inbox->unreadCount($user),
            'agenda' => $agenda, 'latest' => $latest, 'as_of' => $now->setTimezone(self::TIMEZONE), 'timezone' => self::TIMEZONE];
    }

    private function bookingTime(): int
    {
        $watermark = DB::table('reservation_hold_clock')->where('id', 1)->value('high_water_us');
        // InnoDB stores the watermark in DECIMAL(49,30); PDO returns its exact decimal string.
        if (DB::connection()->getDriverName() === 'mysql' && is_string($watermark)
            && preg_match('/^(0|[1-9][0-9]{0,18})\.0+$/D', $watermark, $parts)
            && (string) (int) $parts[1] === $parts[1]) {
            $watermark = (int) $parts[1];
        }
        $now = BookingClock::micros();
        if (! is_int($watermark) || $watermark < 0 || $now < $watermark) {
            throw new ClientDashboardFailure;
        }

        return $now;
    }

    private function storedTimestamp(?string $value): CarbonImmutable
    {
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $value ?? '', 'UTC');
        } catch (InvalidFormatException|\ValueError) {
            throw new ClientDashboardFailure;
        }
        if ($date === null || $date->format('Y-m-d H:i:s') !== $value) {
            throw new ClientDashboardFailure;
        }

        return $date;
    }
}
