<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
   public function up(): void
{
    Schema::table('likes', function (Blueprint $table) {
        $table->unsignedBigInteger('likeable_id')->nullable()->after('user_id');
        $table->string('likeable_type')->nullable()->after('likeable_id');
    });
}

    public function down(): void
{
    Schema::table('likes', function (Blueprint $table) {
        $table->dropColumn(['likeable_id', 'likeable_type']);
    });
}
};