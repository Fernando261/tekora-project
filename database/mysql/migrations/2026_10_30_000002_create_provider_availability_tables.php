<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE provider_availability_settings (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                provider_profile_id BIGINT UNSIGNED NOT NULL UNIQUE,
                timezone VARCHAR(64) NOT NULL,
                revision BIGINT NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                CONSTRAINT availability_settings_profile_fk FOREIGN KEY (provider_profile_id) REFERENCES provider_profiles (id) ON DELETE RESTRICT,
                CONSTRAINT availability_timezone_length CHECK (CHAR_LENGTH(timezone) BETWEEN 1 AND 64),
                CONSTRAINT availability_revision_valid CHECK (revision >= 1)
            ) ENGINE=InnoDB
            SQL);
        DB::statement(<<<'SQL'
            CREATE TABLE provider_weekly_intervals (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                provider_profile_id BIGINT UNSIGNED NOT NULL,
                day_of_week TINYINT UNSIGNED NOT NULL,
                start_minute SMALLINT UNSIGNED NOT NULL,
                end_minute SMALLINT UNSIGNED NOT NULL,
                CONSTRAINT availability_intervals_profile_fk FOREIGN KEY (provider_profile_id) REFERENCES provider_profiles (id) ON DELETE RESTRICT,
                CONSTRAINT availability_weekday_valid CHECK (day_of_week BETWEEN 1 AND 7),
                CONSTRAINT availability_minutes_valid CHECK (start_minute < end_minute AND end_minute <= 1439),
                UNIQUE KEY availability_weekly_order (provider_profile_id, day_of_week, start_minute, end_minute)
            ) ENGINE=InnoDB
            SQL);
        DB::statement(<<<'SQL'
            CREATE TABLE provider_blocked_dates (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                provider_profile_id BIGINT UNSIGNED NOT NULL,
                blocked_date DATE NOT NULL,
                CONSTRAINT availability_dates_profile_fk FOREIGN KEY (provider_profile_id) REFERENCES provider_profiles (id) ON DELETE RESTRICT,
                CONSTRAINT availability_calendar_date CHECK (
                    blocked_date BETWEEN '2000-01-01' AND '2100-12-31'
                    AND DAYOFMONTH(blocked_date) BETWEEN 1 AND COALESCE(DAYOFMONTH(LAST_DAY(blocked_date)), 0)
                ),
                UNIQUE KEY availability_profile_date (provider_profile_id, blocked_date)
            ) ENGINE=InnoDB
            SQL);

        $this->createOverlapTrigger('insert', '');
        $this->createOverlapTrigger('update', 'AND id <> OLD.id');
    }

    private function createOverlapTrigger(string $event, string $excludeCurrent): void
    {
        // Use the same profile mutex as the application. The locking read sees the
        // current intervals even if this transaction has an older consistent snapshot.
        DB::unprepared(<<<SQL
            CREATE TRIGGER provider_weekly_no_overlap_{$event} BEFORE {$event} ON provider_weekly_intervals
            FOR EACH ROW
            BEGIN
                DECLARE locked_profile BIGINT UNSIGNED DEFAULT NULL;
                DECLARE overlap_id BIGINT UNSIGNED DEFAULT NULL;
                SELECT id INTO locked_profile FROM provider_profiles WHERE id = NEW.provider_profile_id FOR UPDATE;
                SELECT id INTO overlap_id FROM provider_weekly_intervals
                    WHERE provider_profile_id = NEW.provider_profile_id AND day_of_week = NEW.day_of_week
                        AND start_minute < NEW.end_minute AND end_minute > NEW.start_minute {$excludeCurrent}
                    LIMIT 1 FOR SHARE;
                IF overlap_id IS NOT NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Overlapping weekly intervals';
                END IF;
            END
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_blocked_dates');
        Schema::dropIfExists('provider_weekly_intervals');
        Schema::dropIfExists('provider_availability_settings');
    }
};
