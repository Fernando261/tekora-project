<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('likes', function (Blueprint $table) {
            // requiere doctrine/dbal para modify()
            $table->unsignedBigInteger('post_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('likes', function (Blueprint $table) {
            $table->unsignedBigInteger('post_id')->nullable(false)->change();
        });
    }
};