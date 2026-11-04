<?php

namespace App\Models;

use App\Enums\ActivityNotificationEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityNotification extends Model
{
    public $timestamps = false;
    protected $guarded = ['*'];
    protected $visible = ['id', 'user_id', 'event', 'created_at', 'read_at'];

    // Fechas UTC literales: la bandeja las interpreta explícitamente, sin depender de APP_TIMEZONE.
    protected function casts(): array
    {
        return ['user_id' => 'integer', 'service_reservation_id' => 'integer', 'service_review_id' => 'integer',
            'reservation_version' => 'integer', 'event' => ActivityNotificationEvent::class];
    }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function reservation(): BelongsTo { return $this->belongsTo(ServiceReservation::class, 'service_reservation_id'); }
    public function review(): BelongsTo { return $this->belongsTo(ServiceReview::class, 'service_review_id'); }
    public function scopeUnread(Builder $query): void { $query->whereNull('read_at'); }
}
