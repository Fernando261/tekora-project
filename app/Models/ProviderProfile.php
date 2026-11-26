<?php

namespace App\Models;

use App\Support\TrustSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ProviderProfile extends Model
{
    protected $fillable = ['display_name', 'headline', 'bio'];

    protected function casts(): array
    {
        return ['user_id' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function credential(): HasOne
    {
        return $this->hasOne(ProfessionalCredential::class, 'user_id', 'user_id');
    }

    public function scopeWithPublicCredential(Builder $query, ?bool $available = null): void
    {
        if ($available ?? TrustSchema::available()) {
            $query->with('credential:user_id,status,license_number,public_license,evidence_path');
        }
    }

    public function publicCredential(): array
    {
        $credential = $this->relationLoaded('credential') ? $this->credential : null;

        return [
            'status' => $credential?->evidence_path !== null ? $credential->status : 'unverified',
            'badge' => $credential?->status === 'verified' && $credential->evidence_path !== null ? 'Profesional Tekora' : null,
            'license_number' => $credential?->public_license ? $credential->license_number : null,
        ];
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    public function availabilitySetting(): HasOne
    {
        return $this->hasOne(ProviderAvailabilitySetting::class);
    }

    public function weeklyIntervals(): HasMany
    {
        return $this->hasMany(ProviderWeeklyInterval::class);
    }

    public function blockedDates(): HasMany
    {
        return $this->hasMany(ProviderBlockedDate::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(ServiceReservation::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(ServiceReview::class);
    }
}
