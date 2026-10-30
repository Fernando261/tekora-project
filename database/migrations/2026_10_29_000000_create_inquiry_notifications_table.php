<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inquiry_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('service_inquiry_id')->constrained()->restrictOnDelete();
            $table->enum('event', ['created', 'accepted', 'rejected', 'cancelled', 'completed']);
            $table->timestamp('created_at');
            $table->timestamp('read_at')->nullable();
            $table->unique(['user_id', 'service_inquiry_id', 'event'], 'inquiry_notifications_one_event');
            $table->index(['user_id', 'id']);
            $table->index(['user_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inquiry_notifications');
    }
};
