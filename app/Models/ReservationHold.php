<?php

namespace App\Models;

use App\Support\BookingClock;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReservationHold extends Model
{
    protected $primaryKey = 'service_reservation_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['accepted_at_us' => 'integer', 'expires_at_us' => 'integer', 'version' => 'integer', 'accepted_by' => 'integer', 'last_actor_user_id' => 'integer', 'closed_at_us' => 'integer'];
    }

    public function events(): HasMany
    {
        return $this->hasMany(ReservationHoldEvent::class, 'service_reservation_id', 'service_reservation_id');
    }

    public function remainingSeconds(): int
    {
        if ($this->state !== 'held') {
            return 0;
        }

        return max(0, intdiv(max(0, $this->expires_at_us - BookingClock::observedMicros()) + 999999, 1000000));
    }
}
