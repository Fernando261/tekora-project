<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('comments', function (Blueprint $table) {
            $table->text('content')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Para poder volver a NOT NULL, primero asegura que no existan NULLs
        \DB::table('comments')->whereNull('content')->update(['content' => '']);

        Schema::table('comments', function (Blueprint $table) {
            $table->text('content')->nullable(false)->change();
        });
    }
};