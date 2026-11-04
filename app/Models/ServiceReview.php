<?php

namespace App\Models;

use App\Enums\ReviewVisibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceReview extends Model
{
    public $timestamps = false;
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['rating' => 'integer', 'service_reservation_id' => 'integer', 'service_id' => 'integer',
            'provider_profile_id' => 'integer', 'client_user_id' => 'integer', 'withdrawn_by_user_id' => 'integer', 'status' => ReviewVisibility::class];
    }

    public function reservation(): BelongsTo { return $this->belongsTo(ServiceReservation::class, 'service_reservation_id'); }
    public function service(): BelongsTo { return $this->belongsTo(Service::class); }
    public function providerProfile(): BelongsTo { return $this->belongsTo(ProviderProfile::class); }
    public function client(): BelongsTo { return $this->belongsTo(User::class, 'client_user_id'); }

    public function scopePubliclyVisible(Builder $query): void
    {
        $query->where('service_reviews.status', ReviewVisibility::Published->value)
            ->whereIn('service_reviews.service_id', Service::publiclyVisible()->select('id'))
            ->whereHas('reservation', fn (Builder $reservations) => $reservations->where('status', 'completed')
                ->whereColumn('service_reservations.service_id', 'service_reviews.service_id')
                ->whereColumn('service_reservations.provider_profile_id', 'service_reviews.provider_profile_id')
                ->whereColumn('service_reservations.client_user_id', 'service_reviews.client_user_id'));
    }

    public function publicMonth(): string
    {
        $months = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

        return $months[(int) substr($this->created_at, 5, 2)].' de '.substr($this->created_at, 0, 4);
    }
}
