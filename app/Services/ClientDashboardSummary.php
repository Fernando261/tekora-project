<?php

namespace App\Services;

use App\Enums\Permission;
use App\Exceptions\ClientDashboardFailure;
use App\Models\ServiceInquiry;
use App\Models\ServiceReservation;
use App\Models\User;
use Carbon\CarbonImmutable;
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
        $now = CarbonImmutable::now('UTC')->startOfSecond();
        $instant = $now->format('Y-m-d H:i:s');
        // Both roots are always the authenticated CLIENT, including on dual-role accounts.
        $inquiries = ServiceInquiry::where('service_inquiries.client_user_id', $user->id);
        $reservations = ServiceReservation::where('service_reservations.client_user_id', $user->id);
        $active = (clone $inquiries)->active()->count();
        $totals = (clone $reservations)->selectRaw("COUNT(CASE WHEN status = 'confirmed' AND starts_at_utc > ? THEN 1 END) AS confirmed,
            COUNT(CASE WHEN status = 'requested' AND starts_at_utc > ? THEN 1 END) AS requested,
            COUNT(CASE WHEN status = 'completed' THEN 1 END) AS completed,
            COUNT(CASE WHEN status = 'cancelled' THEN 1 END) AS cancelled", [$instant, $instant])->first();
        $unreadQuery = app(InquiryReadTracking::class)->withUnreadCount((clone $inquiries)->select('service_inquiries.id'), $user);
        $unread = (int) DB::query()->fromSub($unreadQuery->toBase(), 'client_conversations')->sum('unread_messages_count');
        $agenda = (clone $reservations)->where('service_reservations.status', 'confirmed')->where('starts_at_utc', '>', $instant)
            ->join('services', 'services.id', '=', 'service_reservations.service_id')->orderBy('starts_at_utc')->orderBy('service_reservations.id')
            ->limit(self::LIST_LIMIT)->get(['service_reservations.id', 'services.title', 'starts_at_utc', 'ends_at_utc'])
            ->map(fn ($row) => ['id' => $row->id, 'title' => $row->title, 'url' => route('reservations.show', $row->id),
                'iso' => CarbonImmutable::parse($row->starts_at_utc, 'UTC')->toIso8601String(),
                'date' => CarbonImmutable::parse($row->starts_at_utc, 'UTC')->setTimezone(self::TIMEZONE)->format('d/m/Y H:i'),
                'end' => CarbonImmutable::parse($row->ends_at_utc, 'UTC')->setTimezone(self::TIMEZONE)->format('H:i')])->all();
        $latest = (clone $inquiries)->join('services', 'services.id', '=', 'service_inquiries.service_id')
            ->orderByDesc('service_inquiries.created_at')->orderByDesc('service_inquiries.id')->limit(self::LIST_LIMIT)
            ->get(['service_inquiries.id', 'services.title', 'service_inquiries.status', 'service_inquiries.created_at'])
            ->map(fn ($row) => ['id' => $row->id, 'title' => $row->title, 'status' => $row->status,
                'url' => route('inquiries.show', $row->id), 'iso' => CarbonImmutable::parse($row->getRawOriginal('created_at'), 'UTC')->toIso8601String(),
                'date' => CarbonImmutable::parse($row->getRawOriginal('created_at'), 'UTC')->setTimezone(self::TIMEZONE)->format('d/m/Y H:i')])->all();

        return ['active' => $active, 'confirmed' => (int) $totals->confirmed, 'requested' => (int) $totals->requested,
            'completed' => (int) $totals->completed, 'cancelled' => (int) $totals->cancelled, 'unread' => $unread,
            // This counter intentionally describes the whole account, never just the client role.
            'notifications' => app(NotificationInbox::class)->unreadCount($user),
            'agenda' => $agenda, 'latest' => $latest, 'as_of' => $now->setTimezone(self::TIMEZONE), 'timezone' => self::TIMEZONE];
    }
}
