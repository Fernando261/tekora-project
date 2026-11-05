<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ConversationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['inquiry_id' => $this->id, 'service_id' => $this->service_id, 'service_title' => $this->service_title,
            'status' => $this->status->value, 'unread_messages_count' => (int) $this->unread_messages_count,
            'latest_message_id' => $this->latest_message_id === null ? null : (int) $this->latest_message_id,
            'created_at' => $this->created_at->toISOString()];
    }
}
