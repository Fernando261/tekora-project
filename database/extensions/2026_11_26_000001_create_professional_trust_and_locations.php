<?php

use App\Support\Municipalities;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('terms_acceptances', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('version', 24);
            $table->dateTime('accepted_at');
            $table->primary(['user_id', 'version']);
        });
        Schema::create('professional_credentials', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained()->restrictOnDelete();
            $table->string('license_number', 16)->nullable();
            $table->boolean('public_license')->default(false);
            $table->string('evidence_path', 100)->nullable();
            $table->string('evidence_mime', 32)->nullable();
            $table->enum('status', ['unverified', 'pending', 'verified', 'rejected']);
            $table->unsignedInteger('revision');
            $table->index(['status', 'user_id']);
        });
        Schema::create('professional_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_user_id')->constrained('professional_credentials', 'user_id')->restrictOnDelete();
            $table->foreignId('reviewer_user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('revision');
            $table->enum('decision', ['verified', 'rejected', 'revoked']);
            $table->string('reason', 500);
            $table->dateTime('reviewed_at');
        });
        Schema::create('municipalities', function (Blueprint $table) {
            $table->string('slug', 48)->primary();
            $table->string('name', 100);
        });
        Schema::create('service_locations', function (Blueprint $table) {
            $table->foreignId('service_id')->primary()->constrained()->cascadeOnDelete();
            $table->string('municipality_slug', 48)->index();
            $table->foreign('municipality_slug')->references('slug')->on('municipalities')->restrictOnDelete();
        });
        DB::table('municipalities')->insert(Municipalities::rows());
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            throw new LogicException('Restaura el esquema sellado desde un respaldo aislado.');
        }
        foreach (['service_locations', 'municipalities', 'professional_reviews', 'professional_credentials', 'terms_acceptances'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
