<?php

namespace App\Support;

use Illuminate\Support\Facades\Schema;

final class TrustSchema
{
    public static function available(): bool
    {
        // Historical MySQL laboratories intentionally run without the later extensions.
        return Schema::hasTable('service_locations');
    }
}
