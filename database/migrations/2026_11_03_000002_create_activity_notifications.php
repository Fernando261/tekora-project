<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') throw new RuntimeException('Las notificaciones de actividad requieren las garantías transaccionales de SQLite.');

        DB::statement(<<<'SQL'
            CREATE TABLE activity_notifications (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
                service_reservation_id INTEGER REFERENCES service_reservations(id) ON DELETE RESTRICT,
                service_review_id INTEGER REFERENCES service_reviews(id) ON DELETE RESTRICT,
                event TEXT NOT NULL CHECK(event IN ('reservation_requested','reservation_confirmed','reservation_rejected','reservation_cancelled','reservation_completed','review_published','review_withdrawn')),
                reservation_version INTEGER CHECK(reservation_version IS NULL OR (typeof(reservation_version) = 'integer' AND reservation_version >= 1)),
                created_at TEXT NOT NULL CHECK(length(created_at) = 19 AND strftime('%Y-%m-%d %H:%M:%S', created_at, '+0 seconds') IS NOT NULL
                    AND strftime('%Y-%m-%d %H:%M:%S', created_at, '+0 seconds') = created_at),
                read_at TEXT CHECK(read_at IS NULL OR (length(read_at) = 19 AND strftime('%Y-%m-%d %H:%M:%S', read_at, '+0 seconds') IS NOT NULL
                    AND strftime('%Y-%m-%d %H:%M:%S', read_at, '+0 seconds') = read_at AND read_at >= created_at)),
                CHECK((service_reservation_id IS NOT NULL AND service_review_id IS NULL AND reservation_version IS NOT NULL AND event LIKE 'reservation_%')
                    OR (service_reservation_id IS NULL AND service_review_id IS NOT NULL AND reservation_version IS NULL AND event IN ('review_published','review_withdrawn'))),
                FOREIGN KEY(service_reservation_id, reservation_version) REFERENCES reservation_events(service_reservation_id, version) ON DELETE RESTRICT
            )
            SQL);
        DB::statement('CREATE UNIQUE INDEX activity_one_reservation_version ON activity_notifications(user_id, service_reservation_id, reservation_version) WHERE service_reservation_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX activity_one_review_event ON activity_notifications(user_id, service_review_id, event) WHERE service_review_id IS NOT NULL');
        DB::statement('CREATE INDEX activity_inbox_order ON activity_notifications(user_id, created_at DESC, id DESC)');
        DB::statement('CREATE INDEX activity_unread ON activity_notifications(user_id, created_at DESC, id DESC) WHERE read_at IS NULL');
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER activity_reservation_recipient BEFORE INSERT ON activity_notifications
            WHEN NEW.service_reservation_id IS NOT NULL AND NOT EXISTS (
                SELECT 1 FROM reservation_events e JOIN service_reservations r ON r.id = e.service_reservation_id
                JOIN provider_profiles p ON p.id = r.provider_profile_id
                WHERE r.id = NEW.service_reservation_id AND e.version = NEW.reservation_version
                AND NEW.event = 'reservation_' || e.to_status AND NEW.created_at = e.occurred_at_utc
                AND NEW.user_id != e.actor_user_id
                AND NEW.user_id = CASE WHEN e.to_status = 'requested' OR (e.to_status = 'cancelled' AND e.actor_user_id = r.client_user_id)
                    THEN p.user_id ELSE r.client_user_id END
            ) BEGIN SELECT RAISE(ABORT, 'activity_reservation_recipient'); END;

            CREATE TRIGGER activity_review_recipient BEFORE INSERT ON activity_notifications
            WHEN NEW.service_review_id IS NOT NULL AND NOT EXISTS (
                SELECT 1 FROM service_reviews r JOIN provider_profiles p ON p.id = r.provider_profile_id
                WHERE r.id = NEW.service_review_id AND NEW.user_id = p.user_id AND NEW.user_id != r.client_user_id
                AND ((NEW.event = 'review_published' AND NEW.created_at = r.created_at)
                    OR (NEW.event = 'review_withdrawn' AND r.status = 'withdrawn' AND NEW.created_at = r.withdrawn_at_utc))
            ) BEGIN SELECT RAISE(ABORT, 'activity_review_recipient'); END;

            CREATE TRIGGER activity_initial_unread BEFORE INSERT ON activity_notifications
            WHEN NEW.read_at IS NOT NULL BEGIN SELECT RAISE(ABORT, 'activity_initial_unread'); END;

            CREATE TRIGGER activity_immutable BEFORE UPDATE ON activity_notifications
            WHEN NEW.id IS NOT OLD.id OR NEW.user_id IS NOT OLD.user_id OR NEW.service_reservation_id IS NOT OLD.service_reservation_id
                OR NEW.service_review_id IS NOT OLD.service_review_id OR NEW.event IS NOT OLD.event
                OR NEW.reservation_version IS NOT OLD.reservation_version OR NEW.created_at IS NOT OLD.created_at
                OR OLD.read_at IS NOT NULL OR NEW.read_at IS NULL
            BEGIN SELECT RAISE(ABORT, 'activity_immutable'); END;

            CREATE TRIGGER activity_no_delete BEFORE DELETE ON activity_notifications
            BEGIN SELECT RAISE(ABORT, 'activity_history_required'); END;

            -- Integración transaccional con todos los caminos del workflow, incluidos cierres automáticos y SQL válido.
            CREATE TRIGGER activity_reservation_event AFTER INSERT ON reservation_events
            BEGIN
                INSERT INTO activity_notifications(user_id, service_reservation_id, reservation_version, event, created_at)
                SELECT CASE WHEN NEW.to_status = 'requested' OR (NEW.to_status = 'cancelled' AND NEW.actor_user_id = r.client_user_id)
                        THEN p.user_id ELSE r.client_user_id END,
                    r.id, NEW.version, 'reservation_' || NEW.to_status, NEW.occurred_at_utc
                FROM service_reservations r JOIN provider_profiles p ON p.id = r.provider_profile_id
                WHERE r.id = NEW.service_reservation_id AND NEW.actor_user_id !=
                    CASE WHEN NEW.to_status = 'requested' OR (NEW.to_status = 'cancelled' AND NEW.actor_user_id = r.client_user_id)
                        THEN p.user_id ELSE r.client_user_id END;
            END;

            CREATE TRIGGER activity_review_published AFTER INSERT ON service_reviews
            BEGIN
                INSERT INTO activity_notifications(user_id, service_review_id, event, created_at)
                SELECT p.user_id, NEW.id, 'review_published', NEW.created_at FROM provider_profiles p
                WHERE p.id = NEW.provider_profile_id AND p.user_id != NEW.client_user_id;
            END;

            CREATE TRIGGER activity_review_withdrawn AFTER UPDATE OF status ON service_reviews
            WHEN OLD.status = 'published' AND NEW.status = 'withdrawn'
            BEGIN
                INSERT INTO activity_notifications(user_id, service_review_id, event, created_at)
                SELECT p.user_id, NEW.id, 'review_withdrawn', NEW.withdrawn_at_utc FROM provider_profiles p
                WHERE p.id = NEW.provider_profile_id AND p.user_id != NEW.withdrawn_by_user_id;
            END;
            SQL);
    }

    public function down(): void
    {
        foreach (['activity_reservation_event', 'activity_review_published', 'activity_review_withdrawn'] as $trigger) DB::unprepared('DROP TRIGGER IF EXISTS '.$trigger);
        DB::statement('DROP TABLE IF EXISTS activity_notifications');
    }
};
