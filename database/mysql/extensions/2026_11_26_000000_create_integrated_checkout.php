<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE service_reservations ADD checkout_required TINYINT NOT NULL DEFAULT 0 CHECK (checkout_required IN (0,1)), ADD CONSTRAINT checkout_bound_quote CHECK (checkout_required=0 OR quote_id IS NOT NULL)');
        DB::statement(<<<'SQL'
            CREATE TABLE integrated_checkout_attempts (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                reservation_id BIGINT UNSIGNED NOT NULL,
                quote_id VARCHAR(36) NOT NULL,
                client_user_id BIGINT UNSIGNED NOT NULL,
                provider_user_id BIGINT UNSIGNED NOT NULL,
                amount_cents BIGINT UNSIGNED NOT NULL CHECK (amount_cents BETWEEN 1 AND 1000000000),
                currency CHAR(3) NOT NULL CHECK (currency='MXN'),
                commission_cents BIGINT UNSIGNED NOT NULL,
                method VARCHAR(4) NOT NULL,
                status VARCHAR(24) NOT NULL,
                reference CHAR(36) NOT NULL UNIQUE,
                idempotency_key CHAR(36) NOT NULL,
                attempted_at DATETIME(6) NOT NULL,
                received_at DATETIME(6) NULL,
                successful_reservation BIGINT UNSIGNED GENERATED ALWAYS AS (
                    CASE WHEN status IN ('test_approved','awaiting_cash','cash_received') THEN reservation_id END
                ) STORED UNIQUE,
                CHECK (commission_cents=((amount_cents*15+50) DIV 100)),
                CHECK ((method='card' AND status IN ('test_approved','declined','temporary_failure') AND received_at IS NULL)
                    OR (method='cash' AND ((status='awaiting_cash' AND received_at IS NULL)
                        OR (status='cash_received' AND received_at IS NOT NULL AND received_at>=attempted_at)))),
                FOREIGN KEY (reservation_id) REFERENCES service_reservations(id) ON DELETE RESTRICT,
                FOREIGN KEY (quote_id,client_user_id) REFERENCES service_quotes(id,client_user_id) ON DELETE RESTRICT,
                FOREIGN KEY (quote_id) REFERENCES quote_acceptances(quote_id) ON DELETE RESTRICT,
                FOREIGN KEY (provider_user_id) REFERENCES users(id) ON DELETE RESTRICT,
                UNIQUE (client_user_id,idempotency_key),
                KEY checkout_history (reservation_id,id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER integrated_checkout_insert BEFORE INSERT ON integrated_checkout_attempts FOR EACH ROW
            BEGIN
                DECLARE binding_id BIGINT UNSIGNED DEFAULT NULL;
                SELECT r.id INTO binding_id
                FROM service_reservations r JOIN provider_profiles p ON p.id=r.provider_profile_id
                JOIN reservation_holds h ON h.service_reservation_id=r.id JOIN service_quotes q ON q.id=r.quote_id
                JOIN service_inquiries i ON i.id=r.service_inquiry_id
                WHERE r.id=NEW.reservation_id AND r.checkout_required=1 AND r.status='requested'
                    AND r.quote_id=NEW.quote_id AND r.client_user_id=NEW.client_user_id
                    AND p.user_id=NEW.provider_user_id AND i.status='accepted'
                    AND q.total_cents=NEW.amount_cents AND q.commission_cents=NEW.commission_cents
                    AND h.state='held' AND h.expires_at_us>tekora_booking_now_us()
                    AND r.starts_at_utc>SYSDATE() AND NEW.received_at IS NULL
                FOR SHARE;
                IF binding_id IS NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='integrated_checkout_ineligible';
                END IF;
            END
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER integrated_checkout_update BEFORE UPDATE ON integrated_checkout_attempts FOR EACH ROW
            BEGIN
                DECLARE slot_id BIGINT UNSIGNED DEFAULT NULL;
                IF NEW.id<>OLD.id OR NEW.reservation_id<>OLD.reservation_id OR NEW.quote_id<>OLD.quote_id
                    OR NEW.client_user_id<>OLD.client_user_id OR NEW.provider_user_id<>OLD.provider_user_id
                    OR NEW.amount_cents<>OLD.amount_cents OR NEW.commission_cents<>OLD.commission_cents
                    OR NEW.currency<>OLD.currency OR NEW.method<>OLD.method OR NEW.reference<>OLD.reference
                    OR NEW.idempotency_key<>OLD.idempotency_key OR NEW.attempted_at<>OLD.attempted_at
                    OR OLD.status<>'awaiting_cash' OR NEW.status<>'cash_received' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='integrated_checkout_immutable';
                END IF;
                SELECT r.id INTO slot_id
                FROM service_reservations r JOIN reservation_holds h ON h.service_reservation_id=r.id
                WHERE r.id=OLD.reservation_id AND (
                    (r.status='requested' AND h.state='held' AND h.expires_at_us>tekora_booking_now_us() AND r.starts_at_utc>SYSDATE())
                    OR (r.status='confirmed' AND h.state='committed')
                ) FOR SHARE;
                IF slot_id IS NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='integrated_checkout_ineligible';
                END IF;
            END
            SQL);
        DB::unprepared("CREATE TRIGGER integrated_checkout_delete BEFORE DELETE ON integrated_checkout_attempts FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='integrated_checkout_immutable'");
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER integrated_checkout_commit BEFORE UPDATE ON service_reservations FOR EACH ROW
            BEGIN
                DECLARE selection_id BIGINT UNSIGNED DEFAULT NULL;
                IF NEW.checkout_required<>OLD.checkout_required THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='integrated_checkout_binding_immutable';
                END IF;
                IF OLD.checkout_required=1 AND OLD.status='requested' AND NEW.status='confirmed' THEN
                    SELECT a.id INTO selection_id FROM integrated_checkout_attempts a
                    WHERE a.successful_reservation=OLD.id AND a.quote_id=OLD.quote_id AND a.client_user_id=OLD.client_user_id
                    FOR SHARE;
                    IF selection_id IS NULL THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='integrated_checkout_required';
                    END IF;
                END IF;
            END
            SQL);
    }

    public function down(): void
    {
        throw new LogicException('El historial de checkout se recupera desde un respaldo aislado.');
    }
};
