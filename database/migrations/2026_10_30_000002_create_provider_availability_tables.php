<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') throw new RuntimeException('La disponibilidad de esta etapa requiere SQLite.');
        DB::statement("CREATE TABLE provider_availability_settings (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            provider_profile_id INTEGER NOT NULL UNIQUE REFERENCES provider_profiles(id) ON DELETE RESTRICT,
            timezone TEXT NOT NULL CHECK(length(timezone) BETWEEN 1 AND 64),
            revision INTEGER NOT NULL CHECK(typeof(revision) = 'integer' AND revision >= 1),
            created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)");
        DB::statement("CREATE TABLE provider_weekly_intervals (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            provider_profile_id INTEGER NOT NULL REFERENCES provider_profiles(id) ON DELETE RESTRICT,
            day_of_week INTEGER NOT NULL CHECK(typeof(day_of_week) = 'integer' AND day_of_week BETWEEN 1 AND 7),
            start_minute INTEGER NOT NULL CHECK(typeof(start_minute) = 'integer' AND start_minute BETWEEN 0 AND 1439),
            end_minute INTEGER NOT NULL CHECK(typeof(end_minute) = 'integer' AND end_minute BETWEEN 0 AND 1439),
            CHECK(start_minute < end_minute),
            UNIQUE(provider_profile_id, day_of_week, start_minute, end_minute))");
        DB::statement('CREATE INDEX provider_weekly_order ON provider_weekly_intervals (provider_profile_id, day_of_week, start_minute)');
        DB::statement("CREATE TRIGGER provider_weekly_no_overlap_insert BEFORE INSERT ON provider_weekly_intervals
            WHEN EXISTS (SELECT 1 FROM provider_weekly_intervals WHERE provider_profile_id = NEW.provider_profile_id
            AND day_of_week = NEW.day_of_week AND start_minute < NEW.end_minute AND end_minute > NEW.start_minute)
            BEGIN SELECT RAISE(ABORT, 'Overlapping weekly intervals'); END");
        DB::statement("CREATE TRIGGER provider_weekly_no_overlap_update BEFORE UPDATE ON provider_weekly_intervals
            WHEN EXISTS (SELECT 1 FROM provider_weekly_intervals WHERE id != OLD.id AND provider_profile_id = NEW.provider_profile_id
            AND day_of_week = NEW.day_of_week AND start_minute < NEW.end_minute AND end_minute > NEW.start_minute)
            BEGIN SELECT RAISE(ABORT, 'Overlapping weekly intervals'); END");
        DB::statement("CREATE TABLE provider_blocked_dates (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            provider_profile_id INTEGER NOT NULL REFERENCES provider_profiles(id) ON DELETE RESTRICT,
            blocked_date TEXT NOT NULL CHECK(length(blocked_date) = 10
                AND blocked_date GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]'
                AND blocked_date BETWEEN '2000-01-01' AND '2100-12-31'
                AND date(blocked_date, '+0 days') IS NOT NULL AND date(blocked_date, '+0 days') = blocked_date),
            UNIQUE(provider_profile_id, blocked_date))");
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_blocked_dates');
        // SQLite elimina también los dos triggers pertenecientes a esta tabla nueva.
        Schema::dropIfExists('provider_weekly_intervals');
        Schema::dropIfExists('provider_availability_settings');
    }
};
