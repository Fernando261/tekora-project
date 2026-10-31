<?php

namespace App\Models;

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
}
