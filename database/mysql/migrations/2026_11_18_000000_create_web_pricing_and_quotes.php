<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $uuid = static fn (string $column) => "REGEXP_LIKE({$column}, '^[0-9a-f]{8}(-[0-9a-f]{4}){3}-[0-9a-f]{12}$', 'c')
            AND CHAR_LENGTH({$column}) = 36";
        $utc = static fn (string $column) => "CHAR_LENGTH({$column}) = 26
            AND REGEXP_LIKE({$column}, '^[1-9][0-9]{3}-(0[1-9]|1[0-2])-[0-9]{2} ([01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9][.][0-9]{6}$', 'c')
            AND COALESCE(DAYOFMONTH({$column}), 0) BETWEEN 1 AND COALESCE(DAYOFMONTH(LAST_DAY({$column})), 0)";
        $engine = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin';
        // Validate the input as canonical integer text before any numeric conversion. Both
        // INTEGER and scaled DECIMAL can discard fractional input before a CHECK sees it.
        $amount = <<<'SQL'
            total_cents VARCHAR(80) NOT NULL CHECK (REGEXP_LIKE(total_cents, '^[1-9][0-9]{0,9}$', 'c')
                AND REGEXP_LIKE(total_cents, '[^0-9]', 'c') = 0 AND CAST(total_cents AS UNSIGNED) <= 1000000000),
            commission_cents VARCHAR(80) NOT NULL CHECK (REGEXP_LIKE(commission_cents, '^(0|[1-9][0-9]{0,9})$', 'c')
                AND REGEXP_LIKE(commission_cents, '[^0-9]', 'c') = 0),
            provider_cents VARCHAR(80) NOT NULL CHECK (REGEXP_LIKE(provider_cents, '^(0|[1-9][0-9]{0,9})$', 'c')
                AND REGEXP_LIKE(provider_cents, '[^0-9]', 'c') = 0),
            currency VARCHAR(3) NOT NULL CHECK (currency = 'MXN'),
            duration_minutes VARCHAR(80) NOT NULL CHECK (duration_minutes = '60'),
            policy_version VARCHAR(40) NOT NULL CHECK (policy_version = 'synthetic-commercial-v1-15pct-half-up'),
            commission_basis_points VARCHAR(80) NOT NULL CHECK (commission_basis_points = '1500'),
            provider_basis_points VARCHAR(80) NOT NULL CHECK (provider_basis_points = '8500'),
            CHECK (CAST(commission_cents AS UNSIGNED) = ((CAST(total_cents AS UNSIGNED) * 15 + 50) DIV 100)),
            CHECK (CAST(provider_cents AS UNSIGNED) = CAST(total_cents AS SIGNED) - CAST(commission_cents AS SIGNED))
            SQL;

        DB::statement("CREATE TABLE pricing_write_lock (id DECIMAL(31,30) PRIMARY KEY CHECK (id = 1)) {$engine}");
        DB::table('pricing_write_lock')->insert(['id' => 1]);
        DB::statement(<<<SQL
            CREATE TABLE service_price_versions (
                id VARCHAR(36) PRIMARY KEY CHECK ({$uuid('id')}),
                service_id BIGINT UNSIGNED NOT NULL,
                provider_profile_id BIGINT UNSIGNED NOT NULL,
                version DECIMAL(39,30) NOT NULL CHECK (version BETWEEN 1 AND 999999999 AND version = FLOOR(version)),
                created_by BIGINT UNSIGNED NOT NULL,
                idempotency_key VARCHAR(36) NOT NULL CHECK ({$uuid('idempotency_key')}),
                created_at_utc VARCHAR(26) NOT NULL CHECK ({$utc('created_at_utc')}),
                {$amount},
                FOREIGN KEY (service_id) REFERENCES services (id) ON DELETE RESTRICT,
                FOREIGN KEY (provider_profile_id) REFERENCES provider_profiles (id) ON DELETE RESTRICT,
                FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT,
                UNIQUE (service_id, version), UNIQUE (service_id, idempotency_key)
            ) {$engine}
            SQL);
        DB::statement(<<<SQL
            CREATE TABLE service_quotes (
                id VARCHAR(36) PRIMARY KEY CHECK ({$uuid('id')}),
                service_inquiry_id BIGINT UNSIGNED NOT NULL,
                service_id BIGINT UNSIGNED NOT NULL,
                provider_profile_id BIGINT UNSIGNED NOT NULL,
                client_user_id BIGINT UNSIGNED NOT NULL,
                price_version_id VARCHAR(36) NOT NULL,
                price_version DECIMAL(39,30) NOT NULL,
                idempotency_key VARCHAR(36) NOT NULL CHECK ({$uuid('idempotency_key')}),
                issued_at_utc VARCHAR(26) NOT NULL CHECK ({$utc('issued_at_utc')}),
                expires_at_utc VARCHAR(26) NOT NULL CHECK ({$utc('expires_at_utc')}),
                {$amount},
                CHECK (COALESCE(TIMESTAMPDIFF(MICROSECOND, issued_at_utc, expires_at_utc), 0) BETWEEN 1000000 AND 86400000000),
                FOREIGN KEY (service_inquiry_id) REFERENCES service_inquiries (id) ON DELETE RESTRICT,
                FOREIGN KEY (service_id) REFERENCES services (id) ON DELETE RESTRICT,
                FOREIGN KEY (provider_profile_id) REFERENCES provider_profiles (id) ON DELETE RESTRICT,
                FOREIGN KEY (client_user_id) REFERENCES users (id) ON DELETE RESTRICT,
                FOREIGN KEY (price_version_id) REFERENCES service_price_versions (id) ON DELETE RESTRICT,
                UNIQUE (client_user_id, idempotency_key), UNIQUE (id, client_user_id),
                KEY service_quotes_inquiry (service_inquiry_id, issued_at_utc, id)
            ) {$engine}
            SQL);
        DB::statement(<<<SQL
            CREATE TABLE quote_acceptances (
                quote_id VARCHAR(36) PRIMARY KEY,
                client_user_id BIGINT UNSIGNED NOT NULL,
                accepted_at_utc VARCHAR(26) NOT NULL CHECK ({$utc('accepted_at_utc')}),
                FOREIGN KEY (client_user_id) REFERENCES users (id) ON DELETE RESTRICT,
                FOREIGN KEY (quote_id, client_user_id) REFERENCES service_quotes (id, client_user_id) ON DELETE RESTRICT
            ) {$engine}
            SQL);
        DB::statement(<<<SQL
            CREATE TABLE service_pricing_creations (
                actor_id BIGINT UNSIGNED NOT NULL,
                idempotency_key VARCHAR(36) NOT NULL CHECK ({$uuid('idempotency_key')}),
                service_id BIGINT UNSIGNED NOT NULL UNIQUE,
                price_version_id VARCHAR(36) NOT NULL UNIQUE,
                fingerprint VARCHAR(64) NOT NULL CHECK (REGEXP_LIKE(fingerprint, '^[0-9a-f]{64}$', 'c') AND CHAR_LENGTH(fingerprint) = 64),
                PRIMARY KEY (actor_id, idempotency_key),
                FOREIGN KEY (actor_id) REFERENCES users (id) ON DELETE RESTRICT,
                FOREIGN KEY (service_id) REFERENCES services (id) ON DELETE RESTRICT,
                FOREIGN KEY (price_version_id) REFERENCES service_price_versions (id) ON DELETE RESTRICT
            ) {$engine}
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER pricing_price_identity BEFORE INSERT ON service_price_versions FOR EACH ROW
            BEGIN
                DECLARE admission DECIMAL(31,30);
                DECLARE parent_id BIGINT UNSIGNED;
                DECLARE latest DECIMAL(39,30);
                SELECT id INTO admission FROM pricing_write_lock WHERE id = 1 FOR UPDATE;
                SELECT s.id INTO parent_id FROM services s JOIN provider_profiles p ON p.id = s.provider_profile_id
                    WHERE s.id = NEW.service_id AND p.id = NEW.provider_profile_id AND p.user_id = NEW.created_by FOR SHARE;
                IF admission IS NULL OR parent_id IS NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'pricing identity';
                END IF;
                SELECT version INTO latest FROM service_price_versions WHERE service_id = NEW.service_id
                    ORDER BY version DESC LIMIT 1 FOR SHARE;
                IF NEW.version <> COALESCE(latest, 0) + 1 THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'pricing sequence';
                END IF;
            END
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER pricing_quote_snapshot BEFORE INSERT ON service_quotes FOR EACH ROW
            BEGIN
                DECLARE admission DECIMAL(31,30);
                DECLARE parent_id BIGINT UNSIGNED;
                DECLARE exact_price VARCHAR(36);
                SELECT id INTO admission FROM pricing_write_lock WHERE id = 1 FOR UPDATE;
                SELECT i.id INTO parent_id FROM service_inquiries i JOIN services s ON s.id = i.service_id
                    JOIN provider_profiles p ON p.id = s.provider_profile_id
                    WHERE i.id = NEW.service_inquiry_id AND i.service_id = NEW.service_id
                    AND i.client_user_id = NEW.client_user_id AND s.provider_profile_id = NEW.provider_profile_id
                    AND p.user_id <> NEW.client_user_id AND i.status = 'accepted' FOR SHARE;
                IF admission IS NULL OR parent_id IS NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'quote identity or state';
                END IF;
                SELECT p.id INTO exact_price FROM service_price_versions p WHERE p.id = NEW.price_version_id
                    AND p.service_id = NEW.service_id AND p.provider_profile_id = NEW.provider_profile_id
                    AND p.version = NEW.price_version AND p.total_cents = NEW.total_cents
                    AND p.commission_cents = NEW.commission_cents AND p.provider_cents = NEW.provider_cents
                    AND p.created_at_utc <= NEW.issued_at_utc FOR SHARE;
                IF exact_price IS NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'quote snapshot';
                END IF;
            END
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER pricing_acceptance_valid BEFORE INSERT ON quote_acceptances FOR EACH ROW
            BEGIN
                DECLARE admission DECIMAL(31,30);
                DECLARE parent_id BIGINT UNSIGNED;
                SELECT id INTO admission FROM pricing_write_lock WHERE id = 1 FOR UPDATE;
                SELECT i.id INTO parent_id FROM service_quotes q JOIN service_inquiries i ON i.id = q.service_inquiry_id
                    WHERE q.id = NEW.quote_id AND q.client_user_id = NEW.client_user_id AND i.status = 'accepted'
                    AND NEW.accepted_at_utc >= q.issued_at_utc AND NEW.accepted_at_utc < q.expires_at_utc FOR SHARE;
                IF admission IS NULL OR parent_id IS NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'acceptance expired or identity';
                END IF;
            END
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER pricing_creation_valid BEFORE INSERT ON service_pricing_creations FOR EACH ROW
            BEGIN
                DECLARE admission DECIMAL(31,30);
                DECLARE initial_price VARCHAR(36);
                SELECT id INTO admission FROM pricing_write_lock WHERE id = 1 FOR UPDATE;
                SELECT p.id INTO initial_price FROM service_price_versions p WHERE p.id = NEW.price_version_id
                    AND p.service_id = NEW.service_id AND p.created_by = NEW.actor_id AND p.version = 1 FOR SHARE;
                IF admission IS NULL OR initial_price IS NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'creation identity';
                END IF;
            END
            SQL);

        $parents = [
            'pricing_service_owner' => ['services', 'NEW.provider_profile_id <> OLD.provider_profile_id', 'service_price_versions', 'service_id', 'priced service owner immutable'],
            'pricing_profile_owner' => ['provider_profiles', 'NEW.user_id <> OLD.user_id', 'service_price_versions', 'provider_profile_id', 'priced profile owner immutable'],
            'pricing_inquiry_identity' => ['service_inquiries', 'NEW.service_id <> OLD.service_id OR NEW.client_user_id <> OLD.client_user_id', 'service_quotes', 'service_inquiry_id', 'quoted inquiry identity immutable'],
        ];
        foreach ($parents as $trigger => [$parent, $condition, $history, $column, $message]) {
            DB::unprepared(<<<SQL
                CREATE TRIGGER {$trigger} BEFORE UPDATE ON {$parent} FOR EACH ROW
                BEGIN
                    DECLARE historical_id VARCHAR(36);
                    IF {$condition} THEN
                        SELECT id INTO historical_id FROM {$history} WHERE {$column} = OLD.id LIMIT 1 FOR SHARE;
                        IF historical_id IS NOT NULL THEN
                            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}';
                        END IF;
                    END IF;
                END
                SQL);
        }

        foreach (['service_price_versions', 'service_quotes', 'quote_acceptances', 'service_pricing_creations', 'pricing_write_lock'] as $table) {
            foreach (['UPDATE', 'DELETE'] as $operation) {
                $trigger = 'pricing_'.$table.'_'.strtolower($operation);
                DB::unprepared("CREATE TRIGGER {$trigger} BEFORE {$operation} ON {$table} FOR EACH ROW
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'pricing append only'");
            }
        }
    }

    public function down(): void
    {
        foreach (['pricing_service_owner', 'pricing_profile_owner', 'pricing_inquiry_identity'] as $trigger) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
        }
        foreach (['service_pricing_creations', 'quote_acceptances', 'service_quotes', 'service_price_versions', 'pricing_write_lock'] as $table) {
            DB::unprepared("DROP TABLE IF EXISTS {$table}");
        }
    }
};
