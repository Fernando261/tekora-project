<?php

namespace App\Http\Resources\Api\V1;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ReservationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $result = ['id' => $this->id, 'inquiry_id' => $this->service_inquiry_id, 'service_id' => $this->service_id,
            'status' => $this->effectiveStatus()->value, 'version' => $this->version, 'timezone' => $this->timezone,
            'date' => $this->local_date, 'time' => $this->localTime(), 'end_time' => $this->localEndTime(),
            'starts_at' => CarbonImmutable::parse($this->starts_at_utc, 'UTC')->toISOString(),
            'ends_at' => CarbonImmutable::parse($this->ends_at_utc, 'UTC')->toISOString(),
            'created_at' => CarbonImmutable::parse($this->getRawOriginal('created_at'), 'UTC')->toISOString()];
        if ($this->quote_id) {
            $hold = $this->hold;
            $result['quote_id'] = $this->quote_id;
            $result['commercial_protocol'] = 'quoted-slot-hold-v1';
            $result['hold'] = $hold ? ['state' => $hold->state, 'version' => $hold->version,
                'accepted_at' => \App\Support\BookingClock::date($hold->accepted_at_us)->toISOString(),
                'expires_at' => \App\Support\BookingClock::date($hold->expires_at_us)->toISOString(),
                'remaining_seconds' => $hold->remainingSeconds(), 'server_now' => \App\Support\BookingClock::date(\App\Support\BookingClock::observedMicros())->toISOString()] : null;
            $result['confirmation_url'] = route('api.v1.reservations.commit', $this->id);
            $result['idempotency_key_required'] = true;
        }

        return $result;
    }
}
