<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_inquiry_messages', function (Blueprint $table) {
            $table->unique(['service_inquiry_id', 'id'], 'inquiry_messages_identity');
            $table->index(['service_inquiry_id', 'author_user_id', 'id'], 'inquiry_messages_received_order');
        });
        Schema::create('service_inquiry_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_inquiry_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('last_read_message_id');
            $table->timestamps();
            $table->unique(['service_inquiry_id', 'user_id'], 'inquiry_reads_one_participant');
            $table->foreign(['service_inquiry_id', 'last_read_message_id'])->references(['service_inquiry_id', 'id'])
                ->on('service_inquiry_messages')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_inquiry_reads');
        Schema::table('service_inquiry_messages', function (Blueprint $table) {
            $table->dropUnique('inquiry_messages_identity');
            $table->dropIndex('inquiry_messages_received_order');
        });
    }
};
