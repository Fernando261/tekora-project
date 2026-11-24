<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Preserve canonical civil/UTC strings without DATETIME's offset conversion on input.
        $local = $this->calendarDay('local_date');
        $start = $this->utcTime('starts_at_utc');
        $end = $this->utcTime('ends_at_utc');
        $created = $this->utcTime('created_at');
        $updated = $this->utcTime('updated_at');
        DB::statement(<<<SQL
            CREATE TABLE service_reservations (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                service_inquiry_id BIGINT UNSIGNED NOT NULL,
                service_id BIGINT UNSIGNED NOT NULL,
                provider_profile_id BIGINT UNSIGNED NOT NULL,
                client_user_id BIGINT UNSIGNED NOT NULL,
                timezone VARCHAR(64) NOT NULL,
                local_date VARCHAR(10) NOT NULL,
                local_start_minute DECIMAL(34,30) NOT NULL,
                starts_at_utc VARCHAR(19) NOT NULL,
                ends_at_utc VARCHAR(19) NOT NULL,
                availability_revision DECIMAL(49,30) NOT NULL,
                idempotency_key VARCHAR(36) NOT NULL,
                status VARCHAR(9) NOT NULL DEFAULT 'requested',
                version DECIMAL(49,30) NOT NULL DEFAULT 1,
                last_actor_user_id BIGINT UNSIGNED NOT NULL,
                last_reason VARCHAR(17) NOT NULL,
                created_at VARCHAR(19) NOT NULL,
                updated_at VARCHAR(19) NOT NULL,
                active_slot TINYINT GENERATED ALWAYS AS
                    (CASE WHEN status IN ('requested', 'confirmed') THEN 1 ELSE NULL END) STORED INVISIBLE,
                CONSTRAINT reservation_timezone CHECK (CHAR_LENGTH(timezone) BETWEEN 1 AND 64),
                CONSTRAINT reservation_local_date CHECK ({$local}),
                CONSTRAINT reservation_local_minute CHECK (local_start_minute BETWEEN 0 AND 1379
                    AND local_start_minute = FLOOR(local_start_minute)),
                CONSTRAINT reservation_start CHECK ({$start} AND RIGHT(starts_at_utc, 2) = '00'),
                CONSTRAINT reservation_duration CHECK ({$end} AND COALESCE(TIMESTAMPDIFF(SECOND, starts_at_utc, ends_at_utc), 0) = 3600),
                CONSTRAINT reservation_availability_revision CHECK (availability_revision BETWEEN 1 AND 9223372036854775807
                    AND availability_revision = FLOOR(availability_revision)),
                CONSTRAINT reservation_key CHECK (CHAR_LENGTH(idempotency_key) = 36 AND LOWER(idempotency_key) = idempotency_key),
                CONSTRAINT reservation_status CHECK (status IN ('requested','confirmed','rejected','cancelled','completed')),
                CONSTRAINT reservation_version CHECK (version BETWEEN 1 AND 9223372036854775807 AND version = FLOOR(version)),
                CONSTRAINT reservation_reason CHECK (last_reason IN ('proposal','confirm','reject','cancel','complete','inquiry_completed')),
                CONSTRAINT reservation_created CHECK ({$created} AND created_at < starts_at_utc),
                CONSTRAINT reservation_updated CHECK ({$updated} AND updated_at >= created_at),
                CONSTRAINT reservation_inquiry_fk FOREIGN KEY (service_inquiry_id) REFERENCES service_inquiries (id) ON DELETE RESTRICT,
                CONSTRAINT reservation_service_fk FOREIGN KEY (service_id) REFERENCES services (id) ON DELETE RESTRICT,
                CONSTRAINT reservation_profile_fk FOREIGN KEY (provider_profile_id) REFERENCES provider_profiles (id) ON DELETE RESTRICT,
                CONSTRAINT reservation_client_fk FOREIGN KEY (client_user_id) REFERENCES users (id) ON DELETE RESTRICT,
                CONSTRAINT reservation_actor_fk FOREIGN KEY (last_actor_user_id) REFERENCES users (id) ON DELETE RESTRICT,
                UNIQUE KEY reservations_idempotency (client_user_id, service_inquiry_id, idempotency_key),
                UNIQUE KEY reservations_one_active_inquiry (service_inquiry_id, active_slot),
                KEY reservations_provider_occupancy (provider_profile_id, status, starts_at_utc, ends_at_utc),
                KEY reservations_client_list (client_user_id, id),
                KEY reservations_provider_list (provider_profile_id, id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin
            SQL);
        DB::statement(<<<'SQL'
            CREATE TABLE reservation_events (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                service_reservation_id BIGINT UNSIGNED NOT NULL,
                version DECIMAL(49,30) NOT NULL,
                from_status VARCHAR(9) NULL,
                to_status VARCHAR(9) NOT NULL,
                actor_user_id BIGINT UNSIGNED NOT NULL,
                reason VARCHAR(17) NOT NULL,
                occurred_at_utc VARCHAR(19) NOT NULL,
                CONSTRAINT reservation_event_version CHECK (version BETWEEN 1 AND 9223372036854775807 AND version = FLOOR(version)),
                CONSTRAINT reservation_event_from CHECK (from_status IN ('requested','confirmed')),
                CONSTRAINT reservation_event_to CHECK (to_status IN ('requested','confirmed','rejected','cancelled','completed')),
                CONSTRAINT reservation_event_reason CHECK (reason IN ('proposal','confirm','reject','cancel','complete','inquiry_completed')),
                CONSTRAINT reservation_event_reservation_fk FOREIGN KEY (service_reservation_id) REFERENCES service_reservations (id) ON DELETE RESTRICT,
                CONSTRAINT reservation_event_actor_fk FOREIGN KEY (actor_user_id) REFERENCES users (id) ON DELETE RESTRICT,
                UNIQUE KEY reservation_event_version_once (service_reservation_id, version)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin
            SQL);

        $this->reservationGuards();
        $this->historyGuards();
        $this->parentGuards();
    }

    private function calendarDay(string $value): string
    {
        return "CHAR_LENGTH({$value}) = 10 AND REGEXP_LIKE({$value}, '^[0-9]{4}-[0-9]{2}-[0-9]{2}$', 'c')
            AND {$value} BETWEEN '2000-01-01' AND '2100-12-31'
            AND DAYOFMONTH({$value}) BETWEEN 1 AND COALESCE(DAYOFMONTH(LAST_DAY({$value})), 0)";
    }

    private function utcTime(string $column): string
    {
        return "CHAR_LENGTH({$column}) = 19
            AND REGEXP_LIKE({$column}, '^[0-9]{4}-[0-9]{2}-[0-9]{2} ([01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]$', 'c')
            AND DAYOFMONTH({$column}) BETWEEN 1 AND COALESCE(DAYOFMONTH(LAST_DAY({$column})), 0)
            AND DAYOFMONTH({$column}) IS NOT NULL";
    }

    private function reservationGuards(): void
    {
        $declarations = <<<'SQL'
            DECLARE inquiry_service, inquiry_client, service_provider, provider_user BIGINT UNSIGNED DEFAULT NULL;
            DECLARE inquiry_status VARCHAR(9) DEFAULT NULL;
            SQL;
        // Lock the accepted inquiry, stabilize its service, then serialize the provider's occupancy.
        $participants = <<<'SQL'
            SELECT service_id, client_user_id, status INTO inquiry_service, inquiry_client, inquiry_status
                FROM service_inquiries WHERE id = NEW.service_inquiry_id FOR UPDATE;
            SELECT provider_profile_id INTO service_provider FROM services WHERE id = inquiry_service FOR SHARE;
            SELECT user_id INTO provider_user FROM provider_profiles WHERE id = service_provider FOR UPDATE;
            IF NOT (inquiry_service <=> NEW.service_id) OR NOT (inquiry_client <=> NEW.client_user_id)
                OR NOT (service_provider <=> NEW.provider_profile_id) OR provider_user IS NULL OR provider_user = NEW.client_user_id THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'reservation_identity';
            END IF;
            IF NEW.status IN ('requested','confirmed') AND inquiry_status <> 'accepted' THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'reservation_inquiry_closed';
            END IF;
            SQL;
        DB::unprepared(<<<SQL
            CREATE TRIGGER reservations_insert_guard BEFORE INSERT ON service_reservations FOR EACH ROW
            BEGIN
                {$declarations}
                IF NEW.status <> 'requested' OR NEW.version <> 1 OR NEW.last_actor_user_id <> NEW.client_user_id
                    OR NEW.last_reason <> 'proposal' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'reservation_initial_state';
                END IF;
                {$participants}
            END
            SQL);
        DB::unprepared(<<<SQL
            CREATE TRIGGER reservations_update_guard BEFORE UPDATE ON service_reservations FOR EACH ROW
            BEGIN
                {$declarations}
                DECLARE conflicting_id BIGINT UNSIGNED DEFAULT NULL;
                IF NEW.id <> OLD.id OR NEW.service_inquiry_id <> OLD.service_inquiry_id OR NEW.service_id <> OLD.service_id
                    OR NEW.provider_profile_id <> OLD.provider_profile_id OR NEW.client_user_id <> OLD.client_user_id
                    OR NEW.timezone <> OLD.timezone OR NEW.local_date <> OLD.local_date OR NEW.local_start_minute <> OLD.local_start_minute
                    OR NEW.starts_at_utc <> OLD.starts_at_utc OR NEW.ends_at_utc <> OLD.ends_at_utc
                    OR NEW.availability_revision <> OLD.availability_revision OR NEW.idempotency_key <> OLD.idempotency_key
                    OR NEW.created_at <> OLD.created_at THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'reservation_immutable';
                END IF;
                {$participants}
                IF NEW.version <> OLD.version + 1 OR NEW.updated_at < OLD.updated_at OR NOT (
                    (OLD.status = 'requested' AND NEW.status = 'confirmed' AND NEW.last_reason = 'confirm'
                        AND NEW.last_actor_user_id = provider_user AND NEW.updated_at < NEW.starts_at_utc) OR
                    (OLD.status = 'requested' AND NEW.status = 'rejected' AND NEW.last_reason = 'reject'
                        AND NEW.last_actor_user_id = provider_user) OR
                    (OLD.status IN ('requested','confirmed') AND NEW.status = 'cancelled' AND NEW.last_reason = 'cancel'
                        AND (OLD.status = 'requested' OR NEW.updated_at < NEW.starts_at_utc)
                        AND NEW.last_actor_user_id IN (NEW.client_user_id, provider_user)) OR
                    (OLD.status = 'requested' AND NEW.status = 'cancelled' AND NEW.last_reason = 'inquiry_completed'
                        AND NEW.last_actor_user_id = provider_user) OR
                    (OLD.status = 'confirmed' AND NEW.status = 'completed' AND NEW.last_reason IN ('complete','inquiry_completed')
                        AND NEW.last_actor_user_id = provider_user AND NEW.updated_at >= NEW.ends_at_utc)
                ) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'reservation_transition';
                END IF;
                IF NEW.status = 'confirmed' THEN
                    SELECT id INTO conflicting_id FROM service_reservations
                        WHERE provider_profile_id = NEW.provider_profile_id AND status = 'confirmed'
                        AND starts_at_utc < NEW.ends_at_utc AND ends_at_utc > NEW.starts_at_utc AND id <> OLD.id
                        LIMIT 1 FOR SHARE;
                    IF conflicting_id IS NOT NULL THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'reservation_overlap';
                    END IF;
                END IF;
            END
            SQL);
    }

    private function historyGuards(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER reservation_events_consistent BEFORE INSERT ON reservation_events FOR EACH ROW
            BEGIN
                DECLARE current_id BIGINT UNSIGNED DEFAULT NULL;
                DECLARE predecessor VARCHAR(9) DEFAULT NULL;
                SELECT id INTO current_id FROM service_reservations WHERE id = NEW.service_reservation_id AND version = NEW.version
                    AND status = NEW.to_status AND last_actor_user_id = NEW.actor_user_id AND last_reason = NEW.reason
                    AND updated_at = NEW.occurred_at_utc FOR SHARE;
                IF NEW.version > 1 THEN
                    SELECT to_status INTO predecessor FROM reservation_events
                        WHERE service_reservation_id = NEW.service_reservation_id AND version = NEW.version - 1 FOR SHARE;
                END IF;
                IF current_id IS NULL OR (NEW.version > 1 AND predecessor IS NULL) OR NOT (NEW.from_status <=> predecessor) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'reservation_event_inconsistent';
                END IF;
            END
            SQL);
        foreach (['INSERT' => 'NULL', 'UPDATE' => 'OLD.status'] as $event => $predecessor) {
            $suffix = strtolower($event);
            DB::unprepared(<<<SQL
                CREATE TRIGGER reservations_history_{$suffix} AFTER {$event} ON service_reservations FOR EACH ROW
                INSERT INTO reservation_events (service_reservation_id, version, from_status, to_status, actor_user_id, reason, occurred_at_utc)
                    VALUES (NEW.id, NEW.version, {$predecessor}, NEW.status, NEW.last_actor_user_id, NEW.last_reason, NEW.updated_at)
                SQL);
        }
        DB::unprepared("CREATE TRIGGER reservations_no_delete BEFORE DELETE ON service_reservations FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'reservation_history_required'");
        foreach (['UPDATE', 'DELETE'] as $event) {
            $suffix = strtolower($event);
            DB::unprepared("CREATE TRIGGER reservation_events_no_{$suffix} BEFORE {$event} ON reservation_events FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'reservation_event_immutable'");
        }
    }

    private function parentGuards(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER reservation_inquiry_guard BEFORE UPDATE ON service_inquiries FOR EACH ROW
            BEGIN
                DECLARE linked_id BIGINT UNSIGNED DEFAULT NULL;
                IF NEW.service_id <> OLD.service_id OR NEW.client_user_id <> OLD.client_user_id THEN
                    SELECT id INTO linked_id FROM service_reservations WHERE service_inquiry_id = OLD.id LIMIT 1 FOR SHARE;
                    IF linked_id IS NOT NULL THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'reservation_identity_immutable';
                    END IF;
                END IF;
                IF NEW.status <> 'accepted' THEN
                    SELECT id INTO linked_id FROM service_reservations
                        WHERE service_inquiry_id = OLD.id AND active_slot = 1 LIMIT 1 FOR SHARE;
                    IF linked_id IS NOT NULL THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'reservation_active_inquiry';
                    END IF;
                END IF;
            END
            SQL);
        foreach (['services' => ['provider_profile_id', 'service_id'], 'provider_profiles' => ['user_id', 'provider_profile_id']] as $table => [$parentKey, $reservationKey]) {
            DB::unprepared(<<<SQL
                CREATE TRIGGER reservation_identity_{$table} BEFORE UPDATE ON {$table} FOR EACH ROW
                BEGIN
                    DECLARE linked_id BIGINT UNSIGNED DEFAULT NULL;
                    IF NEW.{$parentKey} <> OLD.{$parentKey} THEN
                        SELECT id INTO linked_id FROM service_reservations WHERE {$reservationKey} = OLD.id LIMIT 1 FOR SHARE;
                        IF linked_id IS NOT NULL THEN
                            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'reservation_identity_immutable';
                        END IF;
                    END IF;
                END
                SQL);
        }
    }

    public function down(): void
    {
        foreach (['reservation_inquiry_guard', 'reservation_identity_services', 'reservation_identity_provider_profiles'] as $trigger) {
            DB::unprepared('DROP TRIGGER IF EXISTS '.$trigger);
        }
        Schema::dropIfExists('reservation_events');
        Schema::dropIfExists('service_reservations');
    }
};
