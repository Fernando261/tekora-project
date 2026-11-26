<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (PHP_INT_SIZE !== 8 || DB::connection()->getDriverName() !== 'mysql'
            || ! preg_match('/^8\.4\.\d+(?:-commercial)?$/D', DB::selectOne('SELECT VERSION() AS version')->version)
            || (int) DB::selectOne('SELECT @@foreign_key_checks AS enabled')->enabled !== 1
            || DB::selectOne('SELECT @@session.time_zone AS zone')->zone !== '+00:00') {
            throw new RuntimeException('B2 requiere MySQL 8.4, UTC, PHP de 64 bits y claves foráneas.');
        }

        DB::unprepared(<<<'SQL'
            CREATE FUNCTION tekora_booking_now_us() RETURNS BIGINT NOT DETERMINISTIC NO SQL
            BEGIN
                IF NOT REGEXP_LIKE(@@session.time_zone, '^[+-][0-9]{2}:[0-9]{2}$', 'c') THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'reservation_clock_timezone';
                END IF;
                RETURN TIMESTAMPDIFF(MICROSECOND, '1970-01-01 00:00:00', CONVERT_TZ(SYSDATE(6), @@session.time_zone, '+00:00'));
            END
            SQL);
        $this->tables();

        $effective = 'GREATEST(tekora_booking_now_us(), (SELECT high_water_us FROM reservation_hold_clock WHERE id=1))';
        $active = $this->active($effective);
        DB::unprepared(<<<SQL
            CREATE VIEW reservation_active_bookings AS
                SELECT r.id AS reservation_id, r.service_inquiry_id, r.provider_profile_id, r.starts_at_utc, r.ends_at_utc,
                    r.status, h.state AS hold_state
                FROM service_reservations r LEFT JOIN reservation_holds h ON h.service_reservation_id=r.id
                WHERE {$active}
            SQL);
        DB::unprepared("CREATE VIEW reservation_occupancy AS
            SELECT reservation_id, provider_profile_id, starts_at_utc, ends_at_utc FROM reservation_active_bookings
            WHERE status='confirmed' OR hold_state='held'");

        DB::unprepared(<<<'SQL'
            CREATE PROCEDURE reservation_sample_clock(OUT watermark BIGINT, OUT sampled BIGINT)
            BEGIN
                SET watermark = NULL;
                SELECT high_water_us INTO watermark FROM reservation_hold_clock WHERE id=1 FOR UPDATE;
                SET sampled = tekora_booking_now_us();
                IF watermark IS NULL OR sampled < watermark THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'reservation_clock_backwards';
                END IF;
            END
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER hold_clock_monotonic BEFORE UPDATE ON reservation_hold_clock FOR EACH ROW
            BEGIN
                IF NEW.id <> OLD.id OR NEW.high_water_us < OLD.high_water_us
                    OR (NEW.high_water_us > OLD.high_water_us AND NEW.high_water_us > tekora_booking_now_us()) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'reservation_clock_invalid';
                END IF;
            END
            SQL);
        DB::unprepared("CREATE TRIGGER hold_clock_no_delete BEFORE DELETE ON reservation_hold_clock FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'reservation_clock_required'");

        $this->reservationGuards();
        $this->holdGuards();
        $this->activityGuards();
    }

    private function tables(): void
    {
        $engine = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin';
        DB::statement('ALTER TABLE service_reservations ADD KEY reservations_inquiry_current (service_inquiry_id,id)');
        DB::statement('ALTER TABLE service_reservations
            DROP INDEX reservations_one_active_inquiry, DROP COLUMN active_slot,
            ADD COLUMN quote_id VARCHAR(36) NULL,
            ADD CONSTRAINT reservation_quote_fk FOREIGN KEY (quote_id) REFERENCES service_quotes(id) ON DELETE RESTRICT');
        DB::statement("CREATE TABLE reservation_hold_clock (
            id TINYINT PRIMARY KEY CHECK(id=1),
            high_water_us DECIMAL(49,30) NOT NULL CHECK(high_water_us BETWEEN 0 AND 9223372036854775807 AND high_water_us=FLOOR(high_water_us))
        ) {$engine}");
        DB::table('reservation_hold_clock')->insert(['id' => 1, 'high_water_us' => 0]);
        DB::statement(<<<SQL
            CREATE TABLE reservation_holds (
                service_reservation_id BIGINT UNSIGNED PRIMARY KEY,
                accepted_by BIGINT UNSIGNED NOT NULL,
                accepted_at_us DECIMAL(49,30) NOT NULL CHECK(accepted_at_us BETWEEN 1 AND 9223372035954775807 AND accepted_at_us=FLOOR(accepted_at_us)),
                expires_at_us DECIMAL(49,30) NOT NULL,
                state VARCHAR(9) NOT NULL CHECK(state IN ('held','committed','released','expired')),
                version DECIMAL(49,30) NOT NULL CHECK(version BETWEEN 1 AND 9223372036854775807 AND version=FLOOR(version)),
                last_actor_user_id BIGINT UNSIGNED NULL,
                closed_at_us DECIMAL(49,30) NULL,
                CHECK(expires_at_us=accepted_at_us+900000000),
                CHECK((state='held' AND closed_at_us IS NULL) OR (state<>'held' AND closed_at_us IS NOT NULL
                    AND closed_at_us BETWEEN accepted_at_us AND 9223372036854775807 AND closed_at_us=FLOOR(closed_at_us))),
                FOREIGN KEY(service_reservation_id) REFERENCES service_reservations(id) ON DELETE RESTRICT,
                FOREIGN KEY(accepted_by) REFERENCES users(id) ON DELETE RESTRICT,
                FOREIGN KEY(last_actor_user_id) REFERENCES users(id) ON DELETE RESTRICT,
                KEY reservation_holds_expiry (state, expires_at_us)
            ) {$engine}
            SQL);
        DB::statement(<<<SQL
            CREATE TABLE reservation_hold_events (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                service_reservation_id BIGINT UNSIGNED NOT NULL,
                version DECIMAL(49,30) NOT NULL,
                from_state VARCHAR(9) NULL CHECK(from_state='held'),
                to_state VARCHAR(9) NOT NULL CHECK(to_state IN ('held','committed','released','expired')),
                actor_user_id BIGINT UNSIGNED NULL,
                occurred_at_us DECIMAL(49,30) NOT NULL CHECK(occurred_at_us BETWEEN 1 AND 9223372036854775807 AND occurred_at_us=FLOOR(occurred_at_us)),
                recorded_at_us DECIMAL(49,30) NOT NULL CHECK(recorded_at_us BETWEEN 1 AND 9223372036854775807 AND recorded_at_us=FLOOR(recorded_at_us)),
                CHECK(recorded_at_us>=occurred_at_us),
                FOREIGN KEY(service_reservation_id) REFERENCES reservation_holds(service_reservation_id) ON DELETE RESTRICT,
                FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE RESTRICT,
                UNIQUE(service_reservation_id, version)
            ) {$engine}
            SQL);
        DB::statement(<<<SQL
            CREATE TABLE reservation_action_receipts (
                actor_user_id BIGINT UNSIGNED NOT NULL,
                idempotency_key VARCHAR(36) NOT NULL CHECK(REGEXP_LIKE(idempotency_key, '^[0-9a-f]{8}(-[0-9a-f]{4}){3}-[0-9a-f]{12}$', 'c')),
                service_reservation_id BIGINT UNSIGNED NOT NULL,
                action VARCHAR(8) NOT NULL CHECK(action IN ('confirm','commit','reject','cancel','complete')),
                expected_version DECIMAL(49,30) NOT NULL CHECK(expected_version BETWEEN 1 AND 9223372036854775807 AND expected_version=FLOOR(expected_version)),
                result_version DECIMAL(49,30) NOT NULL CHECK(result_version BETWEEN 1 AND 9223372036854775807 AND result_version=FLOOR(result_version)),
                recorded_at_us DECIMAL(49,30) NOT NULL CHECK(recorded_at_us BETWEEN 1 AND 9223372036854775807 AND recorded_at_us=FLOOR(recorded_at_us)),
                FOREIGN KEY(service_reservation_id) REFERENCES service_reservations(id) ON DELETE RESTRICT,
                FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE RESTRICT,
                PRIMARY KEY(actor_user_id, idempotency_key)
            ) {$engine}
            SQL);
    }

    private function active(string $time): string
    {
        return "(r.status='confirmed' OR (r.status='requested' AND
            (h.state IS NULL OR (h.state='held' AND h.expires_at_us>{$time}))))";
    }

    private function reservationGuards(): void
    {
        $active = $this->active('sampled');
        $clock = <<<'SQL'
            CALL reservation_sample_clock(watermark, sampled);
            IF EXISTS(SELECT 1 FROM reservation_holds LIMIT 1 FOR SHARE) THEN
                UPDATE reservation_hold_clock SET high_water_us=sampled WHERE id=1;
            END IF;
            SQL;
        $unique = <<<SQL
            SELECT r.id INTO conflict_id FROM service_reservations r
                LEFT JOIN reservation_holds h ON h.service_reservation_id=r.id
                WHERE r.service_inquiry_id=NEW.service_inquiry_id AND r.id<>NEW.id AND {$active}
                LIMIT 1 FOR SHARE;
            IF conflict_id IS NOT NULL THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'reservation_one_active_inquiry';
            END IF;
            SQL;
        DB::unprepared(<<<SQL
            CREATE TRIGGER reservation_b2_insert BEFORE INSERT ON service_reservations FOR EACH ROW PRECEDES reservations_insert_guard
            BEGIN
                DECLARE watermark, sampled BIGINT;
                DECLARE conflict_id BIGINT UNSIGNED DEFAULT NULL;
                DECLARE priced, accepted VARCHAR(36) DEFAULT NULL;
                DECLARE gate DECIMAL(31,30) DEFAULT NULL;
                CALL reservation_sample_clock(watermark, sampled);
                SELECT id INTO gate FROM pricing_write_lock WHERE id=1 FOR UPDATE;
                IF gate IS NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'reservation_price_admission';
                END IF;
                SELECT id INTO priced FROM service_price_versions WHERE service_id=NEW.service_id LIMIT 1 FOR SHARE;
                IF priced IS NOT NULL AND NEW.quote_id IS NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'reservation_accepted_quote_required';
                END IF;
                IF NEW.quote_id IS NOT NULL THEN
                    SELECT q.id INTO accepted FROM service_quotes q JOIN quote_acceptances a ON a.quote_id=q.id
                        WHERE q.id=NEW.quote_id AND q.service_inquiry_id=NEW.service_inquiry_id AND q.service_id=NEW.service_id
                        AND q.provider_profile_id=NEW.provider_profile_id AND q.client_user_id=NEW.client_user_id
                        AND a.client_user_id=NEW.client_user_id FOR SHARE;
                    IF accepted IS NULL THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'reservation_quote_identity';
                    END IF;
                END IF;
                {$clock}
                SET conflict_id = NULL;
                {$unique}
            END
            SQL);
        DB::unprepared('DROP TRIGGER reservations_update_guard');
        DB::unprepared(<<<SQL
            CREATE TRIGGER reservations_update_guard BEFORE UPDATE ON service_reservations FOR EACH ROW
            BEGIN
                DECLARE inquiry_service, inquiry_client, service_provider, provider_user, conflict_id BIGINT UNSIGNED DEFAULT NULL;
                DECLARE inquiry_status, hold_state VARCHAR(9) DEFAULT NULL;
                DECLARE hold_expiry, watermark, sampled BIGINT DEFAULT NULL;
                IF NOT (NEW.quote_id <=> OLD.quote_id) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'reservation_quote_immutable';
                END IF;
                IF NEW.id <> OLD.id OR NEW.service_inquiry_id <> OLD.service_inquiry_id OR NEW.service_id <> OLD.service_id
                    OR NEW.provider_profile_id <> OLD.provider_profile_id OR NEW.client_user_id <> OLD.client_user_id
                    OR NEW.timezone <> OLD.timezone OR NEW.local_date <> OLD.local_date OR NEW.local_start_minute <> OLD.local_start_minute
                    OR NEW.starts_at_utc <> OLD.starts_at_utc OR NEW.ends_at_utc <> OLD.ends_at_utc
                    OR NEW.availability_revision <> OLD.availability_revision OR NEW.idempotency_key <> OLD.idempotency_key
                    OR NEW.created_at <> OLD.created_at THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'reservation_immutable';
                END IF;
                CALL reservation_sample_clock(watermark, sampled);
                SELECT service_id, client_user_id, status INTO inquiry_service, inquiry_client, inquiry_status
                    FROM service_inquiries WHERE id=NEW.service_inquiry_id FOR UPDATE;
                SELECT provider_profile_id INTO service_provider FROM services WHERE id=inquiry_service FOR SHARE;
                SELECT user_id INTO provider_user FROM provider_profiles WHERE id=service_provider FOR UPDATE;
                SELECT state, expires_at_us INTO hold_state, hold_expiry FROM reservation_holds
                    WHERE service_reservation_id=OLD.id FOR SHARE;
                IF NOT (inquiry_service <=> NEW.service_id) OR NOT (inquiry_client <=> NEW.client_user_id)
                    OR NOT (service_provider <=> NEW.provider_profile_id) OR provider_user IS NULL OR provider_user=NEW.client_user_id THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'reservation_identity';
                END IF;
                IF NEW.status IN ('requested','confirmed') AND inquiry_status <> 'accepted' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'reservation_inquiry_closed';
                END IF;
                {$clock}
                IF NEW.version <> OLD.version + 1 OR NEW.updated_at < OLD.updated_at OR NOT (
                    (OLD.status='requested' AND NEW.status='confirmed' AND NEW.last_reason='confirm' AND NEW.updated_at<NEW.starts_at_utc
                        AND TIMESTAMPDIFF(MICROSECOND,'1970-01-01',NEW.starts_at_utc)>sampled
                        AND ((NEW.quote_id IS NULL AND NEW.last_actor_user_id=provider_user)
                            OR (NEW.quote_id IS NOT NULL AND NEW.last_actor_user_id=NEW.client_user_id
                                AND COALESCE(hold_state='held' AND hold_expiry>sampled, FALSE))))
                    OR (OLD.status='requested' AND NEW.status='rejected' AND NEW.last_reason='reject'
                        AND NEW.last_actor_user_id=provider_user AND hold_state IS NULL)
                    OR (OLD.status IN ('requested','confirmed') AND NEW.status='cancelled' AND NEW.last_reason='cancel'
                        AND NEW.last_actor_user_id IN (NEW.client_user_id,provider_user)
                        AND (OLD.status='requested' OR (NEW.updated_at<NEW.starts_at_utc AND TIMESTAMPDIFF(MICROSECOND,'1970-01-01',NEW.starts_at_utc)>sampled)))
                    OR (OLD.status='requested' AND NEW.status='cancelled' AND NEW.last_reason='inquiry_completed' AND NEW.last_actor_user_id=provider_user)
                    OR (OLD.status='confirmed' AND NEW.status='completed' AND NEW.last_reason IN ('complete','inquiry_completed')
                        AND NEW.last_actor_user_id=provider_user AND NEW.updated_at>=NEW.ends_at_utc
                        AND TIMESTAMPDIFF(MICROSECOND,'1970-01-01',NEW.ends_at_utc)<=sampled)
                ) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'reservation_transition';
                END IF;
                IF NEW.status='confirmed' THEN
                    SELECT r.id INTO conflict_id FROM service_reservations r
                        LEFT JOIN reservation_holds h ON h.service_reservation_id=r.id
                        WHERE r.provider_profile_id=NEW.provider_profile_id AND r.id<>OLD.id
                        AND r.starts_at_utc<NEW.ends_at_utc AND r.ends_at_utc>NEW.starts_at_utc
                        AND (r.status='confirmed' OR (r.status='requested' AND h.state='held' AND h.expires_at_us>sampled))
                        LIMIT 1 FOR SHARE;
                    IF conflict_id IS NOT NULL THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'reservation_overlap';
                    END IF;
                    {$unique}
                END IF;
            END
            SQL);
        DB::unprepared('DROP TRIGGER reservation_inquiry_guard');
        DB::unprepared(<<<SQL
            CREATE TRIGGER reservation_inquiry_guard BEFORE UPDATE ON service_inquiries FOR EACH ROW
            BEGIN
                DECLARE linked_id BIGINT UNSIGNED DEFAULT NULL;
                DECLARE sampled, watermark BIGINT;
                IF NEW.service_id <> OLD.service_id OR NEW.client_user_id <> OLD.client_user_id THEN
                    SELECT id INTO linked_id FROM service_reservations WHERE service_inquiry_id=OLD.id LIMIT 1 FOR SHARE;
                    IF linked_id IS NOT NULL THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'reservation_identity_immutable';
                    END IF;
                END IF;
                IF NEW.status <> OLD.status AND NEW.status <> 'accepted' THEN
                    SELECT high_water_us INTO watermark FROM reservation_hold_clock WHERE id=1 FOR SHARE;
                    SET sampled=GREATEST(tekora_booking_now_us(), watermark);
                    SELECT r.id INTO linked_id FROM service_reservations r LEFT JOIN reservation_holds h ON h.service_reservation_id=r.id
                        WHERE r.service_inquiry_id=OLD.id AND {$active} LIMIT 1 FOR SHARE;
                    IF linked_id IS NOT NULL THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'reservation_active_inquiry';
                    END IF;
                END IF;
            END
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER hold_reservation_terminal AFTER UPDATE ON service_reservations FOR EACH ROW PRECEDES reservations_history_update
            BEGIN
                DECLARE watermark, sampled BIGINT;
                IF NEW.status IN ('confirmed','cancelled') THEN
                    CALL reservation_sample_clock(watermark, sampled);
                    UPDATE reservation_holds
                    SET state=CASE WHEN NEW.status='confirmed' THEN 'committed' WHEN expires_at_us<=sampled THEN 'expired' ELSE 'released' END,
                        version=version+1,
                        last_actor_user_id=CASE WHEN NEW.status='cancelled' AND expires_at_us<=sampled THEN NULL ELSE NEW.last_actor_user_id END,
                        closed_at_us=CASE WHEN NEW.status='cancelled' AND expires_at_us<=sampled THEN expires_at_us ELSE sampled END
                    WHERE service_reservation_id=NEW.id AND state='held';
                END IF;
            END
            SQL);
    }

    private function holdGuards(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER hold_initial BEFORE INSERT ON reservation_holds FOR EACH ROW
            BEGIN
                DECLARE watermark, sampled BIGINT;
                DECLARE booking, profile, conflict_id BIGINT UNSIGNED DEFAULT NULL;
                DECLARE slot_start, slot_end VARCHAR(19);
                CALL reservation_sample_clock(watermark, sampled);
                IF NEW.state<>'held' OR NEW.version<>1 OR NOT (NEW.last_actor_user_id <=> NEW.accepted_by) OR NEW.closed_at_us IS NOT NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'reservation_hold_initial';
                END IF;
                SELECT r.id,r.provider_profile_id,r.starts_at_utc,r.ends_at_utc INTO booking,profile,slot_start,slot_end
                    FROM service_reservations r JOIN service_inquiries i ON i.id=r.service_inquiry_id
                    JOIN provider_profiles p ON p.id=r.provider_profile_id
                    JOIN quote_acceptances a ON a.quote_id=r.quote_id AND a.client_user_id=r.client_user_id
                    WHERE r.id=NEW.service_reservation_id AND r.status='requested' AND i.status='accepted' AND p.user_id=NEW.accepted_by FOR SHARE;
                IF booking IS NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'reservation_hold_identity';
                END IF;
                SELECT r.id INTO conflict_id FROM service_reservations r
                    LEFT JOIN reservation_holds h ON h.service_reservation_id=r.id
                    WHERE r.provider_profile_id=profile AND r.id<>booking AND r.starts_at_utc<slot_end AND r.ends_at_utc>slot_start
                    AND (r.status='confirmed' OR (r.status='requested' AND h.state='held' AND h.expires_at_us>GREATEST(tekora_booking_now_us(),watermark)))
                    LIMIT 1 FOR SHARE;
                IF conflict_id IS NOT NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'reservation_overlap';
                END IF;
                CALL reservation_sample_clock(watermark, sampled);
                IF NEW.accepted_at_us<watermark OR NEW.accepted_at_us>sampled OR NEW.expires_at_us<=sampled THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'reservation_hold_clock';
                END IF;
                IF TIMESTAMPDIFF(MICROSECOND,'1970-01-01',slot_start)<=sampled THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'reservation_hold_identity';
                END IF;
                UPDATE reservation_hold_clock SET high_water_us=sampled WHERE id=1;
            END
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER hold_transition BEFORE UPDATE ON reservation_holds FOR EACH ROW
            BEGIN
                DECLARE watermark, sampled BIGINT;
                DECLARE reservation_status VARCHAR(9) DEFAULT NULL;
                DECLARE client_id, actor_id BIGINT UNSIGNED DEFAULT NULL;
                IF NEW.service_reservation_id<>OLD.service_reservation_id OR NEW.accepted_by<>OLD.accepted_by
                    OR NEW.accepted_at_us<>OLD.accepted_at_us OR NEW.expires_at_us<>OLD.expires_at_us THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'reservation_hold_immutable';
                END IF;
                CALL reservation_sample_clock(watermark, sampled);
                IF NEW.state IN ('committed','released') THEN
                    SELECT status, client_user_id, last_actor_user_id INTO reservation_status, client_id, actor_id
                        FROM service_reservations WHERE id=NEW.service_reservation_id FOR SHARE;
                    CALL reservation_sample_clock(watermark, sampled);
                END IF;
                IF OLD.state<>'held' OR NEW.version<>OLD.version+1 OR NOT COALESCE(
                    (NEW.state='expired' AND NEW.expires_at_us<=sampled AND NEW.closed_at_us=NEW.expires_at_us AND NEW.last_actor_user_id IS NULL)
                    OR (NEW.state='committed' AND NEW.expires_at_us>sampled AND NEW.closed_at_us<=sampled
                        AND reservation_status='confirmed' AND actor_id=client_id AND NEW.last_actor_user_id=client_id)
                    OR (NEW.state='released' AND NEW.expires_at_us>sampled AND NEW.closed_at_us<=sampled
                        AND reservation_status='cancelled' AND NEW.last_actor_user_id=actor_id), FALSE) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'reservation_hold_transition';
                END IF;
                UPDATE reservation_hold_clock SET high_water_us=sampled WHERE id=1;
            END
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER hold_event_consistent BEFORE INSERT ON reservation_hold_events FOR EACH ROW
            BEGIN
                DECLARE linked BIGINT UNSIGNED DEFAULT NULL;
                SELECT h.service_reservation_id INTO linked FROM reservation_holds h
                    WHERE h.service_reservation_id=NEW.service_reservation_id AND h.version=NEW.version AND h.state=NEW.to_state
                    AND h.last_actor_user_id <=> NEW.actor_user_id
                    AND NEW.occurred_at_us=COALESCE(h.closed_at_us,h.accepted_at_us) FOR SHARE;
                IF linked IS NULL OR NOT (NEW.from_state <=> IF(NEW.version=1,NULL,'held')) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'reservation_hold_event_inconsistent';
                END IF;
            END
            SQL);
        foreach (['INSERT' => ['NULL', 'NEW.accepted_at_us'], 'UPDATE' => ['OLD.state', 'NEW.closed_at_us']] as $event => [$source, $at]) {
            DB::unprepared('CREATE TRIGGER hold_history_'.strtolower($event)." AFTER {$event} ON reservation_holds FOR EACH ROW
                INSERT INTO reservation_hold_events(service_reservation_id,version,from_state,to_state,actor_user_id,occurred_at_us,recorded_at_us)
                VALUES(NEW.service_reservation_id,NEW.version,{$source},NEW.state,NEW.last_actor_user_id,{$at},GREATEST(tekora_booking_now_us(),{$at}))");
        }
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER hold_receipt_consistent BEFORE INSERT ON reservation_action_receipts FOR EACH ROW
            BEGIN
                DECLARE linked BIGINT UNSIGNED DEFAULT NULL;
                SELECT r.id INTO linked FROM service_reservations r JOIN provider_profiles p ON p.id=r.provider_profile_id
                    LEFT JOIN reservation_holds h ON h.service_reservation_id=r.id
                    WHERE r.id=NEW.service_reservation_id AND r.quote_id IS NOT NULL AND r.version=NEW.result_version
                    AND NEW.actor_user_id IN (r.client_user_id,p.user_id)
                    AND ((NEW.action='confirm' AND r.status='requested' AND r.version=NEW.expected_version AND h.state='held' AND h.accepted_by=NEW.actor_user_id)
                        OR (NEW.action='commit' AND r.status='confirmed' AND r.last_actor_user_id=NEW.actor_user_id AND r.version=NEW.expected_version+1)
                        OR (NEW.action IN ('cancel','reject','complete') AND r.last_reason=NEW.action
                            AND r.last_actor_user_id=NEW.actor_user_id AND r.version=NEW.expected_version+1)) FOR SHARE;
                IF linked IS NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'reservation_receipt_identity';
                END IF;
            END
            SQL);
        DB::unprepared("CREATE TRIGGER hold_no_delete BEFORE DELETE ON reservation_holds FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'reservation_hold_history_required'");
        foreach (['reservation_hold_events', 'reservation_action_receipts'] as $table) {
            foreach (['UPDATE', 'DELETE'] as $event) {
                DB::unprepared('CREATE TRIGGER hold_'.$table.'_'.strtolower($event)." BEFORE {$event} ON {$table} FOR EACH ROW
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'reservation_hold_append_only'");
            }
        }
    }

    private function activityGuards(): void
    {
        DB::unprepared('DROP TRIGGER activity_insert_guard');
        DB::unprepared('DROP TRIGGER activity_reservation_event');
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
        $recipient = $this->reservationRecipient('NEW');
        DB::unprepared(<<<SQL
            CREATE TRIGGER activity_reservation_event AFTER INSERT ON reservation_events FOR EACH ROW
            INSERT INTO activity_notifications (user_id, service_reservation_id, reservation_version, event, created_at)
                SELECT {$recipient}, r.id, NEW.version, CONCAT('reservation_', NEW.to_status), NEW.occurred_at_utc
                FROM service_reservations r JOIN provider_profiles p ON p.id = r.provider_profile_id
                WHERE r.id = NEW.service_reservation_id AND NEW.actor_user_id <> {$recipient}
            SQL);
    }

    private function reservationRecipient(string $event): string
    {
        return "CASE WHEN {$event}.to_status = 'requested'
            OR ({$event}.to_status IN ('cancelled','confirmed') AND {$event}.actor_user_id = r.client_user_id)
            THEN p.user_id ELSE r.client_user_id END";
    }

    public function down(): void
    {
        throw new RuntimeException('B2 MySQL requiere restauración explícita del esquema y su historial.');
    }
};
