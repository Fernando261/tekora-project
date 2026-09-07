<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  public function up(): void {
    Schema::create('citas', function (Blueprint $table) {
      $table->id();
      $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
      $table->foreignId('profesionista_id')->constrained('profesionistas')->onDelete('cascade');

      $table->dateTime('start_datetime');
      $table->dateTime('end_datetime');
      $table->text('motivo')->nullable();

      $table->enum('estado', ['pendiente','aceptada','rechazada','cancelada'])->default('pendiente');

      $table->timestamps();

      $table->unique(['profesionista_id', 'start_datetime'], 'uniq_prof_slot');
      $table->index(['profesionista_id', 'estado']);
    });
  }

  public function down(): void {
    Schema::dropIfExists('citas');
  }
};

