<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class CheckoutContextResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $result = $this->resource;
        $result['quote'] = $result['quote'] ? new QuoteResource($result['quote']) : null;
        $result['amount'] = $result['amount'] ? new CommercialAmountResource($result['amount']) : null;

        return $result;
    }
}
