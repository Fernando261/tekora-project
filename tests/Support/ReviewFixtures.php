<?php

namespace Tests\Support;

use App\Enums\ReservationStatus;
use App\Models\ServiceInquiry;
use App\Models\ServiceReservation;
use App\Models\ServiceReview;
use App\Services\ReservationWorkflow;
use App\Services\ReviewWorkflow;
use Carbon\Carbon;

trait ReviewFixtures
{
    use ReservationFixtures;

    protected function reviewContext(): array
    {
        [$client, $provider, $inquiry] = $this->reservationContext();
        $reservation = $this->completeForReview($inquiry, ['date' => '2030-01-07']);

        return [$client, $provider, $inquiry, $reservation];
    }

    protected function completeForReview(ServiceInquiry $inquiry, array $changes = []): ServiceReservation
    {
        $setting = $inquiry->service->providerProfile->availabilitySetting()->firstOrFail();
        $reservation = $this->proposeReservation($inquiry, $changes + ['date' => now($setting->timezone)->addDay()->format('Y-m-d'), 'availability_revision' => $setting->revision]);
        $workflow = app(ReservationWorkflow::class); $provider = $inquiry->service->providerProfile->user;
        $workflow->decide($provider, $reservation->id, 'confirm', ['version' => 1]);
        $this->travelTo(Carbon::parse($reservation->ends_at_utc, 'UTC'));

        return $workflow->decide($provider, $reservation->id, 'complete', ['version' => 2]);
    }

    protected function publishReview(ServiceReservation $reservation, int $rating = 5, ?string $comment = 'Servicio puntual y claro.'): ServiceReview
    {
        return app(ReviewWorkflow::class)->publish($reservation->client, $reservation->id, compact('rating', 'comment'))['review'];
    }
}
