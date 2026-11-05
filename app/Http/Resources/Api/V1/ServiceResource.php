<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ServiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'title' => $this->title, 'description' => $this->description,
            'modality' => $this->modality->value,
            'category' => ['id' => $this->category->id, 'name' => $this->category->name, 'slug' => $this->category->slug],
            'provider' => new ProviderResource($this->providerProfile)];
    }
}
