<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  public function up(): void
  {
    Schema::create('profesionista_disponibilidades', function (Blueprint $table) {
      $table->id();
      $table->unsignedBigInteger('profesionista_id');

      // 0=Domingo ... 6=Sábado (Carbon::dayOfWeek)
      $table->unsignedTinyInteger('day_of_week');

      // rango
      $table->time('start_time');
      $table->time('end_time');

      $table->boolean('is_active')->default(true);

      $table->timestamps();

      $table->foreign('profesionista_id')
        ->references('id')->on('profesionistas')
        ->onDelete('cascade');

      $table->index(['profesionista_id', 'day_of_week'], 'pd_prof_dow_idx');
    });
  }

  public function down(): void
  {
    Schema::dropIfExists('profesionista_disponibilidades');
  }
};