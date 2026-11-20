<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ContractingJourneyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return array_intersect_key($this->resource, array_flip(['inquiry', 'viewer_role', 'participants', 'progress', 'next_action', 'commercial',
            'reservation', 'review', 'closure', 'history', 'conversation', 'payments', 'confirmation_readiness', 'server_now']));
    }
}
