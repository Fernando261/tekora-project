<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ProviderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'display_name' => $this->display_name,
            'headline' => $this->headline, 'bio' => $this->bio];
    }
}
