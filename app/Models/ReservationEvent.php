<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReservationEvent extends Model
{
    public $timestamps = false;
    protected $guarded = ['*'];

    public function reservation(): BelongsTo { return $this->belongsTo(ServiceReservation::class, 'service_reservation_id'); }
    public function actor(): BelongsTo { return $this->belongsTo(User::class, 'actor_user_id'); }
}
