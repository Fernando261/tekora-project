<?php

namespace Tests\Support;

use App\Enums\InquiryStatus;
use App\Enums\RoleName;
use App\Models\ServiceInquiry;
use App\Models\ServiceReservation;
use App\Services\ProviderAvailability;
use App\Services\ReservationWorkflow;
use Illuminate\Support\Str;

trait ReservationFixtures
{
    use AvailabilityFixtures;

    protected function reservationContext(string $timezone = 'America/Mexico_City'): array
    {
        $this->travelTo(now('UTC')->setDate(2030, 1, 1)->setTime(12, 0));
        $client = $this->account(RoleName::Client); $provider = $this->account(RoleName::Client, RoleName::Provider);
        $inquiry = $this->inquiry($client, $provider, InquiryStatus::Accepted);
        $this->publishSchedule($provider, $timezone);

        return [$client, $provider, $inquiry];
    }

    protected function publishSchedule($provider, string $timezone = 'America/Mexico_City', int $revision = 0, array $changes = []): void
    {
        app(ProviderAvailability::class)->save($provider, $changes + ['revision' => $revision, 'timezone' => $timezone,
            'intervals' => array_map(fn ($day) => ['day_of_week' => $day, 'start' => '08:00', 'end' => '23:59'], range(1, 7)), 'blocked_dates' => []]);
    }

    protected function reservationData(array $changes = []): array
    {
        return $changes + ['date' => '2030-01-07', 'time' => '09:00', 'availability_revision' => 1, 'idempotency_key' => (string) Str::uuid()];
    }

    protected function proposeReservation(ServiceInquiry $inquiry, array $changes = []): ServiceReservation
    {
        return app(ReservationWorkflow::class)->propose($inquiry->client, $inquiry->id, $this->reservationData($changes))['reservation'];
    }

    protected function reservationRows(array $tables): array
    {
        $snapshot = [];
        foreach ($tables as $table) $snapshot[$table] = \Illuminate\Support\Facades\DB::table($table)->get()->map(fn ($row) => (array) $row)->sortBy(fn ($row) => json_encode($row))->values()->all();

        return $snapshot;
    }
}
