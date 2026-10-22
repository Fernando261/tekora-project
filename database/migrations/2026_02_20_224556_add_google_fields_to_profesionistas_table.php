<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // La migración inicial ya incluye estos campos en algunas instalaciones.
        foreach (['google_access_token', 'google_refresh_token', 'google_token_expires_at', 'google_calendar_id'] as $column) {
            if (Schema::hasColumn('profesionistas', $column)) {
                continue;
            }

            Schema::table('profesionistas', function (Blueprint $table) use ($column) {
                if ($column === 'google_token_expires_at') {
                    $table->timestamp($column)->nullable();
                } elseif ($column === 'google_calendar_id') {
                    $table->string($column)->nullable()->default('primary');
                } else {
                    $table->text($column)->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        // No eliminar credenciales: los campos pueden pertenecer a la migración
        // inicial. No es posible atribuirles un propietario con seguridad.
    }
};
