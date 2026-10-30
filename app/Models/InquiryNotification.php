<?php

namespace App\Models;

use App\Enums\InquiryNotificationEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InquiryNotification extends Model
{
    public const UPDATED_AT = null;

    // Ningún dato del formulario puede asignar destinatario, evento o fechas.
    protected $guarded = ['*'];

    // Serializar un aviso tampoco debe incorporar relaciones privadas cargadas por Eloquent.
    protected $visible = ['id', 'user_id', 'service_inquiry_id', 'event', 'created_at', 'read_at'];

    protected function casts(): array
    {
        return ['event' => InquiryNotificationEvent::class, 'user_id' => 'integer',
            'service_inquiry_id' => 'integer', 'created_at' => 'datetime', 'read_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function inquiry(): BelongsTo
    {
        return $this->belongsTo(ServiceInquiry::class, 'service_inquiry_id');
    }

    public function scopeUnread(Builder $query): void
    {
        $query->whereNull('read_at');
    }
}
