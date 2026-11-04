<?php

namespace App\Support;

use App\Enums\ActivityNotificationEvent;
use App\Enums\InquiryNotificationEvent;
use Carbon\Carbon;

class NotificationEntry implements \JsonSerializable
{
    public readonly int $id;
    public readonly string $source_type;
    public readonly ?int $service_inquiry_id;
    public readonly InquiryNotificationEvent|ActivityNotificationEvent $event;
    public readonly Carbon $created_at;
    public readonly ?Carbon $read_at;
    public readonly string $category;
    public readonly string $read_url;

    public function __construct(object $row, public readonly ?string $detail_url, public readonly string $detail_label, public readonly string $unavailable_message)
    {
        $this->id = (int) $row->id; $this->source_type = $row->source_type;
        $this->service_inquiry_id = $row->service_inquiry_id === null ? null : (int) $row->service_inquiry_id;
        $this->event = $row->source_type === 'inquiry' ? InquiryNotificationEvent::from($row->event) : ActivityNotificationEvent::from($row->event);
        $this->category = $this->event instanceof InquiryNotificationEvent ? 'Solicitudes' : $this->event->category();
        $this->created_at = Carbon::parse($row->created_at, 'UTC');
        $this->read_at = $row->read_at === null ? null : Carbon::parse($row->read_at, 'UTC');
        $this->read_url = route($row->source_type === 'inquiry' ? 'notifications.read' : 'notifications.activity.read', $this->id);
    }

    public function jsonSerialize(): array
    {
        return ['id' => $this->id, 'source_type' => $this->source_type, 'event' => $this->event->value,
            'created_at' => $this->created_at->toIso8601String(), 'read_at' => $this->read_at?->toIso8601String(), 'detail_url' => $this->detail_url];
    }
}
