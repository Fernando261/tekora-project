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
    Schema::table('profesionistas', function (Blueprint $table) {
        $table->rememberToken();
    });
}

public function down()
{
    Schema::table('profesionistas', function (Blueprint $table) {
        $table->dropColumn('remember_token');
    });
}
};
