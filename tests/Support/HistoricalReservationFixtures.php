<?php

namespace Tests\Support;

use App\Models\ServiceInquiry;
use App\Models\ServiceReservation;
use App\Services\ReservationTime;
use Illuminate\Support\Facades\DB;

/** Versioned migration fixtures: do not call a post-B2 workflow against a pre-B2 schema. */
trait HistoricalReservationFixtures
{
    protected function historicalProposal(ServiceInquiry $inquiry): ServiceReservation
    {
        $data = $this->reservationData();
        $profile = $inquiry->service->providerProfile;
        $setting = $profile->availabilitySetting()->firstOrFail();
        $slot = app(ReservationTime::class)->resolve($data['date'], $data['time'], $setting->timezone);
        $id = DB::table('service_reservations')->insertGetId(['service_inquiry_id' => $inquiry->id, 'service_id' => $inquiry->service_id,
            'provider_profile_id' => $profile->id, 'client_user_id' => $inquiry->client_user_id, 'timezone' => $setting->timezone,
            'local_date' => $data['date'], 'local_start_minute' => $slot['local_start_minute'], 'starts_at_utc' => $slot['starts_at_utc'],
            'ends_at_utc' => $slot['ends_at_utc'], 'availability_revision' => $setting->revision, 'idempotency_key' => $data['idempotency_key'],
            'status' => 'requested', 'version' => 1, 'last_actor_user_id' => $inquiry->client_user_id, 'last_reason' => 'proposal',
            'created_at' => now('UTC')->format('Y-m-d H:i:s'), 'updated_at' => now('UTC')->format('Y-m-d H:i:s')]);

        return ServiceReservation::findOrFail($id);
    }

    protected function historicalReviewContext(): array
    {
        [$client, $provider, $inquiry] = $this->reservationContext();
        $reservation = $this->historicalProposal($inquiry);
        DB::table('service_reservations')->where('id', $reservation->id)->update(['status' => 'confirmed', 'version' => 2, 'last_actor_user_id' => $provider->id, 'last_reason' => 'confirm']);
        $this->travelTo(\Carbon\Carbon::parse($reservation->ends_at_utc, 'UTC'));
        DB::table('service_reservations')->where('id', $reservation->id)->update(['status' => 'completed', 'version' => 3, 'last_actor_user_id' => $provider->id, 'last_reason' => 'complete', 'updated_at' => now('UTC')->format('Y-m-d H:i:s')]);

        return [$client, $provider, $inquiry, $reservation->fresh()];
    }
}
