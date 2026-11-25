<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Canonical UTC strings preserve the review contract without implicit offset conversion.
        $utc = static fn (string $column) => "CHAR_LENGTH({$column}) = 19
            AND REGEXP_LIKE({$column}, '^[0-9]{4}-[0-9]{2}-[0-9]{2} ([01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]$', 'c')
            AND COALESCE(DAYOFMONTH({$column}), 0) BETWEEN 1 AND COALESCE(DAYOFMONTH(LAST_DAY({$column})), 0)";
        $created = $utc('created_at');
        $withdrawn = $utc('withdrawn_at_utc');
        // An integer column rounds fractional raw inputs before CHECK can reject them.
        DB::statement(<<<SQL
            CREATE TABLE service_reviews (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                service_reservation_id BIGINT UNSIGNED NOT NULL UNIQUE,
                service_id BIGINT UNSIGNED NOT NULL,
                provider_profile_id BIGINT UNSIGNED NOT NULL,
                client_user_id BIGINT UNSIGNED NOT NULL,
                rating DECIMAL(31,30) NOT NULL,
                comment TEXT NULL,
                status VARCHAR(9) NOT NULL DEFAULT 'published',
                created_at VARCHAR(19) NOT NULL,
                withdrawn_at_utc VARCHAR(19) NULL,
                withdrawn_by_user_id BIGINT UNSIGNED NULL,
                CONSTRAINT review_rating CHECK (rating BETWEEN 1 AND 5 AND rating = FLOOR(rating)),
                CONSTRAINT review_comment CHECK (comment IS NULL OR (
                    CHAR_LENGTH(comment) BETWEEN 1 AND 1000 AND LOCATE(X'00', CAST(comment AS BINARY)) = 0)),
                CONSTRAINT review_created CHECK ({$created}),
                CONSTRAINT review_withdrawn_time CHECK (withdrawn_at_utc IS NULL OR ({$withdrawn} AND withdrawn_at_utc >= created_at)),
                CONSTRAINT review_status CHECK ((status = 'published' AND withdrawn_at_utc IS NULL AND withdrawn_by_user_id IS NULL)
                    OR (status = 'withdrawn' AND withdrawn_at_utc IS NOT NULL AND withdrawn_by_user_id IS NOT NULL
                        AND withdrawn_by_user_id = client_user_id)),
                CONSTRAINT review_reservation_fk FOREIGN KEY (service_reservation_id) REFERENCES service_reservations (id) ON DELETE RESTRICT,
                CONSTRAINT review_service_fk FOREIGN KEY (service_id) REFERENCES services (id) ON DELETE RESTRICT,
                CONSTRAINT review_provider_fk FOREIGN KEY (provider_profile_id) REFERENCES provider_profiles (id) ON DELETE RESTRICT,
                CONSTRAINT review_client_fk FOREIGN KEY (client_user_id) REFERENCES users (id) ON DELETE RESTRICT,
                CONSTRAINT review_withdrawn_by_fk FOREIGN KEY (withdrawn_by_user_id) REFERENCES users (id) ON DELETE RESTRICT,
                KEY reviews_service_public (service_id, status, id),
                KEY reviews_provider_public (provider_profile_id, status, id),
                KEY reviews_client_private (client_user_id, id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER reviews_insert_guard BEFORE INSERT ON service_reviews FOR EACH ROW
            BEGIN
                DECLARE completed_id BIGINT UNSIGNED DEFAULT NULL;
                IF NEW.status <> 'published' OR NEW.withdrawn_at_utc IS NOT NULL OR NEW.withdrawn_by_user_id IS NOT NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'review_initial_state';
                END IF;
                SELECT r.id INTO completed_id FROM service_reservations r
                    JOIN service_inquiries i ON i.id = r.service_inquiry_id
                    JOIN services s ON s.id = r.service_id
                    JOIN provider_profiles p ON p.id = r.provider_profile_id
                    WHERE r.id = NEW.service_reservation_id AND r.status = 'completed'
                    AND r.service_id = NEW.service_id AND r.provider_profile_id = NEW.provider_profile_id AND r.client_user_id = NEW.client_user_id
                    AND i.service_id = r.service_id AND i.client_user_id = r.client_user_id AND s.provider_profile_id = p.id
                    AND p.user_id <> NEW.client_user_id AND NEW.created_at >= r.updated_at FOR SHARE;
                IF completed_id IS NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'review_completed_identity';
                END IF;
            END
            SQL);
        // Reservation and participant identities are immutable after the insert guard. Withdrawal
        // only needs the review row, so it never acquires a reservation lock in reverse order.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER reviews_update_guard BEFORE UPDATE ON service_reviews FOR EACH ROW
            BEGIN
                IF NEW.id <> OLD.id OR NEW.service_reservation_id <> OLD.service_reservation_id OR NEW.service_id <> OLD.service_id
                    OR NEW.provider_profile_id <> OLD.provider_profile_id OR NEW.client_user_id <> OLD.client_user_id
                    OR NEW.rating <> OLD.rating OR NOT (CAST(NEW.comment AS BINARY) <=> CAST(OLD.comment AS BINARY))
                    OR NEW.created_at <> OLD.created_at OR OLD.status <> 'published' OR NEW.status <> 'withdrawn' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'review_immutable';
                END IF;
            END
            SQL);
        DB::unprepared("CREATE TRIGGER reviews_no_delete BEFORE DELETE ON service_reviews FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'review_history_required'");
    }

    public function down(): void
    {
        Schema::dropIfExists('service_reviews');
    }
};
