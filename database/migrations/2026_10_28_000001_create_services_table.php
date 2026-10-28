<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('service_category_id')->constrained()->restrictOnDelete();
            $table->string('title', 160);
            $table->text('description');
            $table->enum('modality', ['remote', 'onsite', 'both']);
            $table->enum('status', ['draft', 'published', 'paused'])->default('draft');
            $table->timestamps();
            $table->index(['provider_profile_id', 'status', 'id']);
            $table->index(['service_category_id', 'status', 'id']);
            $table->index(['status', 'modality', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};
