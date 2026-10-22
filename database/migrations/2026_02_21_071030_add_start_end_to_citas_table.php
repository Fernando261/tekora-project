<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach (['starts_at' => 'start_datetime', 'ends_at' => 'end_datetime'] as $column => $source) {
            if (!Schema::hasColumn('citas', $column)) {
                // Nullable permite actualizar una tabla que ya contiene citas.
                Schema::table('citas', fn (Blueprint $table) => $table->dateTime($column)->nullable());
                DB::table('citas')->update([$column => DB::raw($source)]);
            }
        }

        if (!Schema::hasColumn('citas', 'google_event_id')) {
            Schema::table('citas', fn (Blueprint $table) => $table->string('google_event_id')->nullable());
        }

        if (!Schema::hasColumn('citas', 'status')) {
            Schema::table('citas', fn (Blueprint $table) => $table->string('status')->default('pending'));

            foreach (['pendiente' => 'pending', 'aceptada' => 'scheduled', 'rechazada' => 'rejected', 'cancelada' => 'cancelled'] as $estado => $status) {
                DB::table('citas')->where('estado', $estado)->update(['status' => $status]);
            }
        }
    }
    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Reversión conservadora: preservar fechas, estados y referencias Google.
    }
};
