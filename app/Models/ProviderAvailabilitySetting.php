<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProviderAvailabilitySetting extends Model
{
    public const DEFAULT_TIMEZONE = 'America/Mexico_City';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['provider_profile_id' => 'integer', 'revision' => 'integer'];
    }

    public function providerProfile(): BelongsTo
    {
        return $this->belongsTo(ProviderProfile::class);
    }

    public static function timezones(): array
    {
        // Incluir alias IANA válidos (por ejemplo Etc/UTC); Factory representa una zona desconocida.
        return array_values(array_diff(\DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), ['Factory']));
    }
}
