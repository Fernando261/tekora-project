<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class CommercialAmountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['currency' => $this->currency, 'total_cents' => $this->totalCents,
            'commission_cents' => $this->commissionCents, 'provider_cents' => $this->providerCents,
            'commission_basis_points' => $this->commissionBasisPoints, 'commission_included' => true,
            'duration_minutes' => $this->durationMinutes, 'policy_version' => $this->policyVersion];
    }
}
