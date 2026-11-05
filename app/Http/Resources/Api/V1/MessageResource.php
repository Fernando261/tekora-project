<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class MessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'inquiry_id' => $this->service_inquiry_id,
            'is_mine' => $this->author_user_id === $request->user('sanctum')->id,
            'body' => $this->body, 'created_at' => $this->created_at->toISOString()];
    }
}
