<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  public function up(): void {
    Schema::create('profesionista_bloqueos', function (Blueprint $table) {
    $table->id();
    $table->foreignId('profesionista_id')->constrained('profesionistas')->onDelete('cascade');
    $table->dateTime('start_datetime');
    $table->dateTime('end_datetime');
    $table->string('motivo')->nullable();
    $table->timestamps();

    $table->index(['profesionista_id', 'start_datetime', 'end_datetime'], 'idx_prof_blk_range');
});

  }

  public function down(): void {
    Schema::dropIfExists('profesionista_bloqueos');
  }
};
