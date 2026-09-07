<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
{
    Schema::table('citas', function (Blueprint $table) {
        $table->dateTime('starts_at')->after('profesionista_id');
        $table->dateTime('ends_at')->after('starts_at');
        $table->string('google_event_id')->nullable()->after('ends_at');
        $table->string('status')->default('scheduled')->after('google_event_id');
    });
}
    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('citas', function (Blueprint $table) {
            //
        });
    }
};
