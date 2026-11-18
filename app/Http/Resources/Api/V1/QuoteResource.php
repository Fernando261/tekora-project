<?php

namespace App\Http\Resources\Api\V1;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class QuoteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $quote = $this->quote;

        return ['id' => $quote->id, 'inquiry_id' => $quote->inquiryId, 'service_id' => $quote->serviceId,
            'provider_id' => $quote->providerProfileId, 'client_id' => $quote->clientId, 'synthetic' => $quote->synthetic,
            'price_version' => $quote->price->version, 'amount' => new CommercialAmountResource($quote->price->amount),
            'issued_at' => CarbonImmutable::instance($quote->issuedAt)->toISOString(),
            'expires_at' => CarbonImmutable::instance($quote->expiresAt)->toISOString(),
            'acceptance_window_expired' => $this->serverNow >= $quote->expiresAt,
            'acceptance' => new QuoteAcceptanceResource($this->resource),
            'server_now' => CarbonImmutable::instance($this->serverNow)->toISOString()];
    }
}
