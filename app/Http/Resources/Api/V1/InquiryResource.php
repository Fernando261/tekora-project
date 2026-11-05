<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class InquiryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'service_id' => $this->service_id, 'service_title' => $this->service_title,
            'message' => $this->message, 'status' => $this->status->value,
            'created_at' => $this->created_at->toISOString(), 'updated_at' => $this->updated_at->toISOString(),
            'accepted_at' => $this->accepted_at?->toISOString(), 'rejected_at' => $this->rejected_at?->toISOString(),
            'cancelled_at' => $this->cancelled_at?->toISOString(), 'completed_at' => $this->completed_at?->toISOString()];
    }
}
