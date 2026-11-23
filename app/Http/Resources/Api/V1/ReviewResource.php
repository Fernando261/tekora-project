<?php

namespace App\Http\Resources\Api\V1;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ReviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'reservation_id' => $this->service_reservation_id,
            'service_id' => $this->service_id, 'service_title' => $this->service->title,
            'rating' => $this->rating, 'comment' => $this->comment, 'status' => $this->status->value,
            'created_at' => CarbonImmutable::parse($this->created_at, 'UTC')->toISOString(),
            'withdrawn_at' => $this->withdrawn_at_utc === null ? null : CarbonImmutable::parse($this->withdrawn_at_utc, 'UTC')->toISOString()];
    }
}
