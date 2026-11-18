<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class PriceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['version' => $this->version, 'amount' => new CommercialAmountResource($this->amount),
            'quote_required' => true, 'binding_quote' => false];
    }
}
