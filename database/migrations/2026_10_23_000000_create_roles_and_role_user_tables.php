<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 32)->unique();
            $table->string('name', 64);
        });

        // Catálogo únicamente: no asignar roles a cuentas existentes.
        DB::table('roles')->insert([
            ['slug' => 'client', 'name' => 'Cliente'],
            ['slug' => 'provider', 'name' => 'Prestador'],
            ['slug' => 'admin', 'name' => 'Administrador'],
        ]);

        Schema::create('role_user', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('roles')->restrictOnDelete();
            $table->timestamps();
            $table->primary(['user_id', 'role_id']);
            $table->index('role_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_user');
        Schema::dropIfExists('roles');
    }
};
