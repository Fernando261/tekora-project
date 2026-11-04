<?php

namespace App\Services;

use App\Enums\Permission;
use App\Exceptions\DashboardFailure;
use App\Models\ReservationEvent;
use App\Models\Service;
use App\Models\ServiceInquiry;
use App\Models\ServiceReservation;
use App\Models\ServiceReview;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ProviderDashboardMetrics
{
    public const TIMEZONE = 'America/Mexico_City';
    public const LIST_LIMIT = 5;

    public function build(User $user, array $filters = []): array
    {
        Gate::forUser($user)->authorize(Permission::ManageProviderProfile->value);
        $days = array_key_exists('period', $filters) ? $filters['period'] : '30';
        if (array_diff(array_keys($filters), ['period']) !== [] || ! in_array($days, ['7', '30', '90', 7, 30, 90], true)) {
            throw new DashboardFailure('Elige un periodo de 7, 30 o 90 días.', 422);
        }

        $now = CarbonImmutable::now('UTC')->startOfSecond();
        $start = $now->setTimezone(self::TIMEZONE)->startOfDay()->subDays((int) $days - 1);
        $window = ['days' => (int) $days, 'start' => $start, 'now' => $now, 'start_utc' => $start->utc()->format('Y-m-d H:i:s'),
            'end_utc' => $now->format('Y-m-d H:i:s'), 'label' => $start->format('d/m/Y').' al '.$now->setTimezone(self::TIMEZONE)->format('d/m/Y H:i')];
        $profile = $user->providerProfile()->first(['id', 'user_id', 'display_name']);
        if (! $profile) return ['profile' => null, 'window' => $window];
        Gate::forUser($user)->authorize('update', $profile);

        // Toda consulta se deriva del perfil autenticado, nunca de identificadores de la petición.
        $services = Service::where('services.provider_profile_id', $profile->id)->select('id');
        $inquiries = ServiceInquiry::whereIn('service_inquiries.service_id', $services);
        $bounds = [$window['start_utc'], $window['end_utc']];
        $inquiryStats = (clone $inquiries)->selectRaw("COUNT(CASE WHEN created_at >= ? AND created_at <= ? THEN 1 END) AS received,
            COUNT(CASE WHEN status = 'pending' THEN 1 END) AS pending,
            COUNT(CASE WHEN accepted_at >= ? AND accepted_at <= ? THEN 1 END) AS accepted,
            COUNT(CASE WHEN rejected_at >= ? AND rejected_at <= ? THEN 1 END) AS rejected", [...$bounds, ...$bounds, ...$bounds])->first();
        $reservations = ServiceReservation::where('service_reservations.provider_profile_id', $profile->id);
        $reservationStats = (clone $reservations)->selectRaw("COUNT(CASE WHEN status = 'requested' AND starts_at_utc > ? THEN 1 END) AS requested,
            COUNT(CASE WHEN status = 'confirmed' AND starts_at_utc > ? THEN 1 END) AS upcoming", [$window['end_utc'], $window['end_utc']])->first();

        $events = ReservationEvent::whereIn('service_reservation_id', (clone $reservations)->select('id'))
            ->whereIn('to_status', ['completed', 'cancelled'])->whereBetween('occurred_at_utc', $bounds)
            ->selectRaw("COUNT(CASE WHEN to_status = 'completed' THEN 1 END) AS completed, COUNT(CASE WHEN to_status = 'cancelled' THEN 1 END) AS cancelled");
        $series = [];
        for ($i = 0; $i < (int) $days; $i++) {
            $day = $start->addDays($i);
            // Buckets civiles [medianoche, siguiente medianoche); el WHERE global corta en el instante consultado.
            $events->selectRaw("COUNT(CASE WHEN to_status = 'completed' AND occurred_at_utc >= ? AND occurred_at_utc < ? THEN 1 END) AS day_".$i,
                [$day->utc()->format('Y-m-d H:i:s'), $day->addDay()->utc()->format('Y-m-d H:i:s')]);
            $series[] = ['date' => $day->format('Y-m-d'), 'label' => $day->format('d/m/Y')];
        }
        $eventStats = $events->first();
        foreach ($series as $i => &$day) $day['count'] = (int) $eventStats->{'day_'.$i};
        unset($day);
        $maximum = max(1, max(array_column($series, 'count')));
        foreach ($series as $i => &$day) {
            $day['x'] = round(($i + .5) * 900 / (int) $days, 2);
            $day['width'] = round(min(48, 900 / (int) $days * .65), 2);
            $day['height'] = round($day['count'] / $maximum * 130, 2);
        }
        unset($day);

        $reviews = ServiceReview::publiclyVisible()->where('service_reviews.provider_profile_id', $profile->id)
            ->selectRaw('COUNT(*) AS total, AVG(rating) AS average')->first();
        // Reutiliza exactamente el predicado de lectura; la consulta exterior restringe al lado prestador.
        $unreadQuery = app(InquiryReadTracking::class)->withUnreadCount((clone $inquiries)->select('service_inquiries.id'), $user);
        $unread = (int) DB::query()->fromSub($unreadQuery->toBase(), 'provider_conversations')->sum('unread_messages_count');
        $accepted = (int) $inquiryStats->accepted; $rejected = (int) $inquiryStats->rejected;
        $completed = (int) $eventStats->completed; $cancelled = (int) $eventStats->cancelled;
        $metrics = [
            $this->metric('published', 'Servicios publicados', Service::publiclyVisible()->where('provider_profile_id', $profile->id)->count(), 'current', 'Servicios propios visibles en el catálogo público.', 'provider-services.index'),
            $this->metric('received', 'Solicitudes recibidas', (int) $inquiryStats->received, 'period', 'Solicitudes creadas para tus servicios en el periodo.', 'inquiries.received'),
            $this->metric('pending', 'Solicitudes pendientes', (int) $inquiryStats->pending, 'current', 'Solicitudes que siguen pendientes de tu decisión.', 'inquiries.received'),
            $this->metric('acceptance', 'Tasa de aceptación', $this->percentage($accepted, $accepted + $rejected, 'Sin decisiones'), 'period', 'Aceptadas entre aceptadas y rechazadas por fecha de decisión.', 'inquiries.received', $accepted.' de '.($accepted + $rejected).' decisiones'),
            $this->metric('requested', 'Reservas por confirmar', (int) $reservationStats->requested, 'current', 'Propuestas pendientes cuyo inicio todavía es futuro.', 'reservations.index'),
            $this->metric('upcoming', 'Próximas reservas', (int) $reservationStats->upcoming, 'current', 'Reservas confirmadas cuyo inicio todavía es futuro.', 'reservations.index'),
            $this->metric('completed', 'Servicios completados', $completed, 'period', 'Transiciones reales de reserva a completada en el periodo.', 'reservations.index'),
            $this->metric('cancellation', 'Tasa de cancelación', $this->percentage($cancelled, $cancelled + $completed, 'Sin reservas finalizadas'), 'period', 'Canceladas entre canceladas y completadas; no entre todas las creadas.', 'reservations.index', $cancelled.' de '.($cancelled + $completed).' reservas finalizadas'),
            $this->metric('rating', 'Calificación promedio', $reviews->total > 0 ? number_format((float) $reviews->average, 1, '.', '').' / 5' : 'Sin calificaciones', 'current', 'Solo reseñas verificadas que siguen siendo públicas.', null, (int) $reviews->total.' reseñas consideradas', $reviews->total > 0 ? route('public-providers.show', $profile->id).'#resenas' : null),
            $this->metric('unread', 'Mensajes sin leer', $unread, 'current', 'Mensajes recibidos en solicitudes de tus servicios; excluye tu actividad como cliente.', 'conversations.index'),
        ];

        $agenda = (clone $reservations)->where('service_reservations.status', 'confirmed')->where('starts_at_utc', '>', $window['end_utc'])
            ->join('services', 'services.id', '=', 'service_reservations.service_id')
            ->orderBy('starts_at_utc')->orderBy('service_reservations.id')->limit(self::LIST_LIMIT)
            ->get(['service_reservations.id', 'services.title', 'starts_at_utc', 'ends_at_utc'])->map(fn ($row) => [
                'id' => $row->id, 'title' => $row->title, 'url' => route('reservations.show', $row->id),
                'iso' => CarbonImmutable::parse($row->starts_at_utc, 'UTC')->toIso8601String(),
                'date' => CarbonImmutable::parse($row->starts_at_utc, 'UTC')->setTimezone(self::TIMEZONE)->format('d/m/Y H:i'),
                'end' => CarbonImmutable::parse($row->ends_at_utc, 'UTC')->setTimezone(self::TIMEZONE)->format('H:i'),
            ])->all();
        $attention = (clone $inquiries)->where('service_inquiries.status', 'pending')->join('services', 'services.id', '=', 'service_inquiries.service_id')
            ->orderBy('service_inquiries.created_at')->orderBy('service_inquiries.id')->limit(self::LIST_LIMIT)
            ->get(['service_inquiries.id', 'services.title', 'service_inquiries.created_at'])->map(fn ($row) => [
                'id' => $row->id, 'title' => $row->title, 'url' => route('inquiries.show', $row->id),
                'iso' => CarbonImmutable::parse($row->getRawOriginal('created_at'), 'UTC')->toIso8601String(),
                'date' => CarbonImmutable::parse($row->getRawOriginal('created_at'), 'UTC')->setTimezone(self::TIMEZONE)->format('d/m/Y H:i'),
            ])->all();

        return ['profile' => $profile, 'window' => $window, 'metrics' => $metrics, 'series' => $series, 'chart_maximum' => $maximum,
            'completed' => $completed, 'agenda' => $agenda, 'attention' => $attention, 'upcoming' => (int) $reservationStats->upcoming,
            'pending' => (int) $inquiryStats->pending, 'requested' => (int) $reservationStats->requested];
    }

    private function percentage(int $numerator, int $denominator, string $empty): string
    {
        return $denominator === 0 ? $empty : number_format(100 * $numerator / $denominator, 1, '.', '').'%';
    }

    private function metric(string $id, string $label, int|string $value, string $scope, string $description, ?string $route, ?string $note = null, ?string $url = null): array
    {
        return compact('id', 'label', 'value', 'scope', 'description', 'note') + ['url' => $url ?? ($route ? route($route) : null)];
    }
}
