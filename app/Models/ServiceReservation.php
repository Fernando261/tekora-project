<?php

namespace App\Models;

use App\Enums\ReservationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ServiceReservation extends Model
{
    protected $guarded = ['*'];

    // UTC y datos civiles permanecen como cadenas: ningún cast depende de APP_TIMEZONE.
    protected function casts(): array
    {
        return ['status' => ReservationStatus::class, 'service_inquiry_id' => 'integer', 'service_id' => 'integer',
            'provider_profile_id' => 'integer', 'client_user_id' => 'integer', 'local_start_minute' => 'integer',
            'availability_revision' => 'integer', 'version' => 'integer', 'last_actor_user_id' => 'integer'];
    }

    public function inquiry(): BelongsTo
    {
        return $this->belongsTo(ServiceInquiry::class, 'service_inquiry_id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function providerProfile(): BelongsTo
    {
        return $this->belongsTo(ProviderProfile::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_user_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ReservationEvent::class);
    }

    public function review(): HasOne
    {
        return $this->hasOne(ServiceReview::class);
    }

    public function hold(): HasOne
    {
        return $this->hasOne(ReservationHold::class, 'service_reservation_id');
    }

    public function effectiveStatus(?int $observedMicros = null): ReservationStatus
    {
        if (! $this->quote_id || ! $this->hold) {
            return $this->status;
        }
        if ($this->hold->state === 'expired') {
            return ReservationStatus::HoldExpired;
        }
        if ($this->hold->state === 'released') {
            return ReservationStatus::HoldReleased;
        }
        if ($this->status !== ReservationStatus::Requested) {
            return $this->status;
        }

        return match ($this->hold->state) {
            'held' => $this->hold->remainingSeconds($observedMicros) > 0 ? ReservationStatus::Held : ReservationStatus::HoldExpired,
            'expired' => ReservationStatus::HoldExpired,
            'released' => ReservationStatus::HoldReleased,
            default => $this->status,
        };
    }

    public function scopeActive(Builder $query): void
    {
        $query->whereIn('service_reservations.id', \Illuminate\Support\Facades\DB::table('reservation_active_bookings')->select('reservation_id'));
    }

    public function localTime(): string
    {
        return ProviderWeeklyInterval::civilTime($this->local_start_minute);
    }

    public function localEndTime(): string
    {
        return ProviderWeeklyInterval::civilTime($this->local_start_minute + 60);
    }

    public function expired(): bool
    {
        return $this->status === ReservationStatus::Requested && $this->starts_at_utc <= now('UTC')->format('Y-m-d H:i:s');
    }
}
