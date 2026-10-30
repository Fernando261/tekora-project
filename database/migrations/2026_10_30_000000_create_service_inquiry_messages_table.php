<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_inquiry_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_inquiry_id')->constrained()->restrictOnDelete();
            $table->foreignId('author_user_id')->constrained('users')->restrictOnDelete();
            $table->uuid('idempotency_key');
            $table->text('body');
            $table->timestamp('created_at');
            $table->unique(['service_inquiry_id', 'author_user_id', 'idempotency_key'], 'inquiry_messages_one_submission');
            $table->index(['service_inquiry_id', 'created_at', 'id'], 'inquiry_messages_conversation_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_inquiry_messages');
    }
};
