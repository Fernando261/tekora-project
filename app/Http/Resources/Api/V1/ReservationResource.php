<?php

namespace App\Http\Resources\Api\V1;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ReservationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'inquiry_id' => $this->service_inquiry_id, 'service_id' => $this->service_id,
            'status' => $this->status->value, 'version' => $this->version, 'timezone' => $this->timezone,
            'date' => $this->local_date, 'time' => $this->localTime(), 'end_time' => $this->localEndTime(),
            'starts_at' => CarbonImmutable::parse($this->starts_at_utc, 'UTC')->toISOString(),
            'ends_at' => CarbonImmutable::parse($this->ends_at_utc, 'UTC')->toISOString(),
            'created_at' => CarbonImmutable::parse($this->getRawOriginal('created_at'), 'UTC')->toISOString()];
    }
}
