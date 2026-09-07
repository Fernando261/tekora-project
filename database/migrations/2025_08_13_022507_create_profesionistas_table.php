<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{


    public function up()
    {
        Schema::create('profesionistas', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('cedula')->unique();
            $table->text('google_access_token')->nullable();
            $table->text('google_refresh_token')->nullable();
            $table->string('google_calendar_id')->nullable();
            $table->timestamp('google_token_expires_at')->nullable();
            $table->timestamps();
        });
    }


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('profesionistas');
    }
};
