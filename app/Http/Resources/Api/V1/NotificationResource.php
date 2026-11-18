<?php

namespace App\Http\Resources\Api\V1;

use App\Enums\ActivityNotificationEvent;
use App\Enums\InquiryNotificationEvent;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class NotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $inquiry = $this->source_type === 'inquiry';
        $event = $inquiry ? InquiryNotificationEvent::from($this->event) : ActivityNotificationEvent::from($this->event);

        return ['id' => $this->source_type.':'.$this->id, 'source_type' => $this->source_type, 'source_id' => (int) $this->id,
            'event' => $event->value, 'label' => $event->label(),
            'created_at' => CarbonImmutable::parse($this->created_at, 'UTC')->toISOString(),
            'read_at' => $this->read_at === null ? null : CarbonImmutable::parse($this->read_at, 'UTC')->toISOString(),
            'resource' => ['type' => $inquiry ? 'inquiry' : 'reservation',
                'id' => (int) ($inquiry ? $this->service_inquiry_id : $this->service_reservation_id)]];
    }
}
