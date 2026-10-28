<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // La aplicación se valida con SQLite. No omitir silenciosamente la garantía de unicidad en otros motores.
        if (DB::connection()->getDriverName() !== 'sqlite') {
            throw new RuntimeException('Las solicitudes requieren SQLite y su índice único parcial. Revisar la estrategia antes de usar otro motor.');
        }

        Schema::create('service_inquiries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_user_id')->constrained('users')->restrictOnDelete();
            $table->string('service_title', 160);
            $table->text('message');
            $table->enum('status', ['pending', 'accepted', 'rejected', 'cancelled', 'completed'])->default('pending');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['client_user_id', 'status', 'id']);
            $table->index(['service_id', 'status', 'id']);
        });

        DB::statement("CREATE UNIQUE INDEX service_inquiries_one_active ON service_inquiries (service_id, client_user_id) WHERE status IN ('pending', 'accepted')");
    }

    public function down(): void
    {
        Schema::dropIfExists('service_inquiries');
    }
};
