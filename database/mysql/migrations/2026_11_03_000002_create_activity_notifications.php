<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $utc = static fn (string $column) => "CHAR_LENGTH({$column}) = 19
            AND REGEXP_LIKE({$column}, '^[0-9]{4}-[0-9]{2}-[0-9]{2} ([01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]$', 'c')
            AND COALESCE(DAYOFMONTH({$column}), 0) BETWEEN 1 AND COALESCE(DAYOFMONTH(LAST_DAY({$column})), 0)";
        $created = $utc('created_at');
        $read = $utc('read_at');
        DB::statement(<<<SQL
            CREATE TABLE activity_notifications (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id BIGINT UNSIGNED NOT NULL,
                service_reservation_id BIGINT UNSIGNED NULL,
                service_review_id BIGINT UNSIGNED NULL,
                event VARCHAR(24) NOT NULL,
                reservation_version DECIMAL(49,30) NULL,
                created_at VARCHAR(19) NOT NULL,
                read_at VARCHAR(19) NULL,
                CONSTRAINT activity_created_utc CHECK ({$created}),
                CONSTRAINT activity_read_utc CHECK (read_at IS NULL OR ({$read} AND read_at >= created_at)),
                CONSTRAINT activity_version CHECK (reservation_version IS NULL OR (
                    reservation_version BETWEEN 1 AND 9223372036854775807 AND reservation_version = FLOOR(reservation_version))),
                CONSTRAINT activity_source CHECK (
                    (service_reservation_id IS NOT NULL AND service_review_id IS NULL AND reservation_version IS NOT NULL
                        AND event IN ('reservation_requested','reservation_confirmed','reservation_rejected','reservation_cancelled','reservation_completed'))
                    OR (service_reservation_id IS NULL AND service_review_id IS NOT NULL AND reservation_version IS NULL
                        AND event IN ('review_published','review_withdrawn'))),
                CONSTRAINT activity_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT,
                CONSTRAINT activity_reservation_fk FOREIGN KEY (service_reservation_id) REFERENCES service_reservations (id) ON DELETE RESTRICT,
                CONSTRAINT activity_review_fk FOREIGN KEY (service_review_id) REFERENCES service_reviews (id) ON DELETE RESTRICT,
                CONSTRAINT activity_reservation_event_fk FOREIGN KEY (service_reservation_id, reservation_version)
                    REFERENCES reservation_events (service_reservation_id, version) ON DELETE RESTRICT,
                UNIQUE KEY activity_one_reservation_version (user_id, service_reservation_id, reservation_version),
                UNIQUE KEY activity_one_review_event (user_id, service_review_id, event),
                KEY activity_inbox_order (user_id, created_at DESC, id DESC),
                KEY activity_unread (user_id, read_at, created_at DESC, id DESC)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin
            SQL);
        $this->guards();
        $this->emitters();
    }

    private function reservationRecipient(string $event): string
    {
        return "CASE WHEN {$event}.to_status = 'requested' OR ({$event}.to_status = 'cancelled' AND {$event}.actor_user_id = r.client_user_id)
            THEN p.user_id ELSE r.client_user_id END";
    }

    private function guards(): void
    {
        $recipient = $this->reservationRecipient('e');
        DB::unprepared(<<<SQL
            CREATE TRIGGER activity_insert_guard BEFORE INSERT ON activity_notifications FOR EACH ROW
            BEGIN
                DECLARE linked_id BIGINT UNSIGNED DEFAULT NULL;
                IF NEW.read_at IS NOT NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'activity_initial_unread';
                END IF;
                IF NEW.service_reservation_id IS NOT NULL THEN
                    SELECT e.id INTO linked_id FROM reservation_events e JOIN service_reservations r ON r.id = e.service_reservation_id
                        JOIN provider_profiles p ON p.id = r.provider_profile_id
                        WHERE r.id = NEW.service_reservation_id AND e.version = NEW.reservation_version
                        AND NEW.event = CONCAT('reservation_', e.to_status) AND NEW.created_at = e.occurred_at_utc
                        AND NEW.user_id <> e.actor_user_id AND NEW.user_id = {$recipient} FOR SHARE;
                    IF linked_id IS NULL THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'activity_reservation_recipient';
                    END IF;
                ELSEIF NEW.service_review_id IS NOT NULL THEN
                    SELECT r.id INTO linked_id FROM service_reviews r JOIN provider_profiles p ON p.id = r.provider_profile_id
                        WHERE r.id = NEW.service_review_id AND NEW.user_id = p.user_id AND NEW.user_id <> r.client_user_id
                        AND ((NEW.event = 'review_published' AND NEW.created_at = r.created_at)
                            OR (NEW.event = 'review_withdrawn' AND r.status = 'withdrawn' AND NEW.created_at = r.withdrawn_at_utc)) FOR SHARE;
                    IF linked_id IS NULL THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'activity_review_recipient';
                    END IF;
                END IF;
            END
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER activity_immutable BEFORE UPDATE ON activity_notifications FOR EACH ROW
            BEGIN
                IF NEW.id <> OLD.id OR NEW.user_id <> OLD.user_id OR NOT (NEW.service_reservation_id <=> OLD.service_reservation_id)
                    OR NOT (NEW.service_review_id <=> OLD.service_review_id) OR NEW.event <> OLD.event
                    OR NOT (NEW.reservation_version <=> OLD.reservation_version) OR NEW.created_at <> OLD.created_at
                    OR OLD.read_at IS NOT NULL OR NEW.read_at IS NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'activity_immutable';
                END IF;
            END
            SQL);
        DB::unprepared("CREATE TRIGGER activity_no_delete BEFORE DELETE ON activity_notifications FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'activity_history_required'");
    }

    private function emitters(): void
    {
        $recipient = $this->reservationRecipient('NEW');
        DB::unprepared(<<<SQL
            CREATE TRIGGER activity_reservation_event AFTER INSERT ON reservation_events FOR EACH ROW
            INSERT INTO activity_notifications (user_id, service_reservation_id, reservation_version, event, created_at)
                SELECT {$recipient}, r.id, NEW.version, CONCAT('reservation_', NEW.to_status), NEW.occurred_at_utc
                FROM service_reservations r JOIN provider_profiles p ON p.id = r.provider_profile_id
                WHERE r.id = NEW.service_reservation_id AND NEW.actor_user_id <> {$recipient}
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER activity_review_published AFTER INSERT ON service_reviews FOR EACH ROW
            INSERT INTO activity_notifications (user_id, service_review_id, event, created_at)
                SELECT p.user_id, NEW.id, 'review_published', NEW.created_at FROM provider_profiles p
                WHERE p.id = NEW.provider_profile_id AND p.user_id <> NEW.client_user_id
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER activity_review_withdrawn AFTER UPDATE ON service_reviews FOR EACH ROW
            BEGIN
                IF OLD.status = 'published' AND NEW.status = 'withdrawn' THEN
                    INSERT INTO activity_notifications (user_id, service_review_id, event, created_at)
                        SELECT p.user_id, NEW.id, 'review_withdrawn', NEW.withdrawn_at_utc FROM provider_profiles p
                        WHERE p.id = NEW.provider_profile_id AND p.user_id <> NEW.withdrawn_by_user_id;
                END IF;
            END
            SQL);
    }

    public function down(): void
    {
        foreach (['activity_reservation_event', 'activity_review_published', 'activity_review_withdrawn'] as $trigger) {
            DB::unprepared('DROP TRIGGER IF EXISTS '.$trigger);
        }
        Schema::dropIfExists('activity_notifications');
    }
};
