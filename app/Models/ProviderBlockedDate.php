<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProviderBlockedDate extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        // Es una fecha civil, sin convertirla en un instante UTC ni normalizarla por DST.
        return ['provider_profile_id' => 'integer'];
    }

    public function providerProfile(): BelongsTo
    {
        return $this->belongsTo(ProviderProfile::class);
    }
}
