<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ReservationOptionsResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return (new AvailabilityResource(['service_id' => $this->resource['service_id'],
            'availability' => $this->resource['schedule']]))->toArray($request) + [
                'inquiry_id' => $this->resource['inquiry_id'], 'availability_revision' => $this->resource['revision'],
                'min_date' => $this->resource['min_date'], 'max_date' => $this->resource['max_date'],
                'duration_minutes' => 60, 'guaranteed' => false];
    }
}
