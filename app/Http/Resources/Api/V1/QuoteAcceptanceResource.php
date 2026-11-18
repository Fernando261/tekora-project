<?php

namespace App\Http\Resources\Api\V1;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class QuoteAcceptanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['quote_id' => $this->quote->id, 'state' => $this->acceptanceState(),
            'accepted_at' => $this->acceptance ? CarbonImmutable::instance($this->acceptance->acceptedAt)->toISOString() : null,
            'server_now' => CarbonImmutable::instance($this->serverNow)->toISOString()];
    }
}
