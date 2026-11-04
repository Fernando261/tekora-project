<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') throw new RuntimeException('Las reseñas requieren restricciones y triggers de SQLite. Adaptar las garantías antes de cambiar de motor.');

        DB::statement(<<<'SQL'
            CREATE TABLE service_reviews (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                service_reservation_id INTEGER NOT NULL UNIQUE REFERENCES service_reservations(id) ON DELETE RESTRICT,
                service_id INTEGER NOT NULL REFERENCES services(id) ON DELETE RESTRICT,
                provider_profile_id INTEGER NOT NULL REFERENCES provider_profiles(id) ON DELETE RESTRICT,
                client_user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
                rating INTEGER NOT NULL CHECK(typeof(rating) = 'integer' AND rating BETWEEN 1 AND 5),
                comment TEXT CHECK(comment IS NULL OR (typeof(comment) = 'text' AND length(comment) BETWEEN 1 AND 1000 AND instr(comment, char(0)) = 0)),
                status TEXT NOT NULL DEFAULT 'published' CHECK(status IN ('published','withdrawn')),
                created_at TEXT NOT NULL CHECK(length(created_at) = 19 AND strftime('%Y-%m-%d %H:%M:%S', created_at, '+0 seconds') IS NOT NULL
                    AND strftime('%Y-%m-%d %H:%M:%S', created_at, '+0 seconds') = created_at),
                withdrawn_at_utc TEXT CHECK(withdrawn_at_utc IS NULL OR (length(withdrawn_at_utc) = 19
                    AND strftime('%Y-%m-%d %H:%M:%S', withdrawn_at_utc, '+0 seconds') IS NOT NULL
                    AND strftime('%Y-%m-%d %H:%M:%S', withdrawn_at_utc, '+0 seconds') = withdrawn_at_utc AND withdrawn_at_utc >= created_at)),
                withdrawn_by_user_id INTEGER REFERENCES users(id) ON DELETE RESTRICT,
                CHECK((status = 'published' AND withdrawn_at_utc IS NULL AND withdrawn_by_user_id IS NULL)
                    OR (status = 'withdrawn' AND withdrawn_at_utc IS NOT NULL AND withdrawn_by_user_id IS NOT NULL AND withdrawn_by_user_id = client_user_id))
            )
            SQL);
        DB::statement("CREATE INDEX reviews_service_public ON service_reviews(service_id, id) WHERE status = 'published'");
        DB::statement("CREATE INDEX reviews_provider_public ON service_reviews(provider_profile_id, id) WHERE status = 'published'");
        DB::statement('CREATE INDEX reviews_client_private ON service_reviews(client_user_id, id)');
        foreach (['INSERT', 'UPDATE'] as $operation) {
            $suffix = strtolower($operation);
            DB::unprepared("CREATE TRIGGER reviews_completed_identity_{$suffix} BEFORE {$operation} ON service_reviews
                WHEN NOT EXISTS (SELECT 1 FROM service_reservations r JOIN service_inquiries i ON i.id = r.service_inquiry_id
                    JOIN services s ON s.id = r.service_id JOIN provider_profiles p ON p.id = r.provider_profile_id
                    WHERE r.id = NEW.service_reservation_id AND r.status = 'completed'
                    AND r.service_id = NEW.service_id AND r.provider_profile_id = NEW.provider_profile_id AND r.client_user_id = NEW.client_user_id
                    AND i.service_id = r.service_id AND i.client_user_id = r.client_user_id AND s.provider_profile_id = p.id
                    AND p.user_id != NEW.client_user_id AND NEW.created_at >= r.updated_at)
                BEGIN SELECT RAISE(ABORT, 'review_completed_identity'); END");
        }
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER reviews_initial_state BEFORE INSERT ON service_reviews
            WHEN NEW.status != 'published' OR NEW.withdrawn_at_utc IS NOT NULL OR NEW.withdrawn_by_user_id IS NOT NULL
            BEGIN SELECT RAISE(ABORT, 'review_initial_state'); END;

            CREATE TRIGGER reviews_immutable BEFORE UPDATE ON service_reviews
            WHEN NEW.id IS NOT OLD.id OR NEW.service_reservation_id IS NOT OLD.service_reservation_id OR NEW.service_id IS NOT OLD.service_id
                OR NEW.provider_profile_id IS NOT OLD.provider_profile_id OR NEW.client_user_id IS NOT OLD.client_user_id
                OR NEW.rating IS NOT OLD.rating OR NEW.comment IS NOT OLD.comment OR NEW.created_at IS NOT OLD.created_at
                OR OLD.status != 'published' OR NEW.status != 'withdrawn'
            BEGIN SELECT RAISE(ABORT, 'review_immutable'); END;

            CREATE TRIGGER reviews_no_delete BEFORE DELETE ON service_reviews
            BEGIN SELECT RAISE(ABORT, 'review_history_required'); END;
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS service_reviews');
    }
};
