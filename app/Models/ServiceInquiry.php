<?php

namespace App\Models;

use App\Enums\InquiryStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceInquiry extends Model
{
    protected $fillable = ['message'];

    protected function casts(): array
    {
        return ['status' => InquiryStatus::class, 'client_user_id' => 'integer', 'service_id' => 'integer',
            'accepted_at' => 'datetime', 'rejected_at' => 'datetime', 'cancelled_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_user_id');
    }

    public function scopeActive(Builder $query): void
    {
        $query->whereIn('status', [InquiryStatus::Pending->value, InquiryStatus::Accepted->value]);
    }

    public function timeline(): array
    {
        $events = [['label' => 'Solicitud enviada por el cliente', 'at' => $this->created_at]];
        foreach (['accepted_at' => 'Aceptada por el prestador', 'rejected_at' => 'Rechazada por el prestador',
            'cancelled_at' => 'Cancelada por el cliente', 'completed_at' => 'Marcada como finalizada por el prestador'] as $column => $label) {
            if ($this->$column !== null) $events[] = ['label' => $label, 'at' => $this->$column];
        }

        return $events;
    }
}
