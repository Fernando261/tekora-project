<?php

namespace Tests\Support;

use App\Enums\InquiryAction;
use App\Enums\InquiryStatus;
use App\Enums\RoleName;
use App\Models\ServiceInquiry;
use App\Models\User;
use App\Services\InquiryWorkflow;

trait InquiryFixtures
{
    use CatalogFixtures;

    protected function inquiry(?User $client = null, ?User $provider = null, InquiryStatus $status = InquiryStatus::Pending): ServiceInquiry
    {
        $client ??= $this->account(RoleName::Client);
        $provider ??= $this->account(RoleName::Provider);
        $service = $this->service($provider);
        $workflow = app(InquiryWorkflow::class);
        $inquiry = $workflow->open($client, $service->id, 'Necesito ayuda para revisar mi equipo de trabajo.')['inquiry'];
        if (in_array($status, [InquiryStatus::Accepted, InquiryStatus::Completed], true)) {
            $workflow->decide($provider, $inquiry->id, InquiryAction::Accept);
        }
        if ($status === InquiryStatus::Completed) {
            $this->performInquiryReservation($inquiry);
            $workflow->decide($provider, $inquiry->id, InquiryAction::Complete);
        }
        if ($status === InquiryStatus::Rejected) {
            $workflow->decide($provider, $inquiry->id, InquiryAction::Reject);
        }
        if ($status === InquiryStatus::Cancelled) {
            $workflow->decide($client, $inquiry->id, InquiryAction::Cancel);
        }

        return $inquiry->fresh();
    }

    // Historical fixtures now include the same explicit reservation lifecycle as the product.
    // Keep the calling test's clock and an existing provider schedule intact.
    protected function performInquiryReservation(ServiceInquiry $inquiry): \App\Models\ServiceReservation
    {
        $provider = $inquiry->service->providerProfile->user;
        $profile = $provider->providerProfile;
        $setting = $profile->availabilitySetting()->first();
        if (! $setting) {
            $setting = app(\App\Services\ProviderAvailability::class)->save($provider, ['revision' => 0, 'timezone' => 'UTC', 'blocked_dates' => [],
                'intervals' => array_map(fn ($day) => ['day_of_week' => $day, 'start' => '08:00', 'end' => '23:00'], range(1, 7))]);
        }
        for ($days = 1; $days <= 14; $days++) {
            $date = now($setting->timezone)->subDays($days)->startOfDay();
            if ($profile->blockedDates()->where('blocked_date', $date->format('Y-m-d'))->exists()) {
                continue;
            }
            $interval = $profile->weeklyIntervals()->where('day_of_week', $date->dayOfWeekIso)
                ->whereRaw('end_minute - start_minute >= 60')->orderBy('start_minute')->first();
            if (! $interval) {
                continue;
            }
            $start = $date->copy()->addMinutes($interval->start_minute);
            $previousClock = \Illuminate\Support\Carbon::getTestNow();
            try {
                \Illuminate\Support\Carbon::setTestNow($start->copy()->subHour());
                $workflow = app(\App\Services\ReservationWorkflow::class);
                $reservation = $workflow->propose($inquiry->client, $inquiry->id, ['date' => $start->format('Y-m-d'),
                    'time' => $start->format('H:i'), 'availability_revision' => $setting->revision,
                    'idempotency_key' => (string) \Illuminate\Support\Str::uuid()])['reservation'];

                $reservation = $workflow->decide($provider, $reservation->id, 'confirm', ['version' => 1]);
            } finally {
                \Illuminate\Support\Carbon::setTestNow($previousClock);
            }

            return app(\App\Services\ReservationWorkflow::class)->decide($provider, $reservation->id, 'complete', ['version' => 2]);
        }
        throw new \RuntimeException('No se encontró un horario pasado para la fixture realizada.');
    }
}
