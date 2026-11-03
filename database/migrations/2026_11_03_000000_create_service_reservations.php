<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            throw new RuntimeException('Las reservas requieren las garantías de SQLite. Adaptar restricciones y triggers antes de cambiar de motor.');
        }

        DB::statement(<<<'SQL'
            CREATE TABLE service_reservations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                service_inquiry_id INTEGER NOT NULL REFERENCES service_inquiries(id) ON DELETE RESTRICT,
                service_id INTEGER NOT NULL REFERENCES services(id) ON DELETE RESTRICT,
                provider_profile_id INTEGER NOT NULL REFERENCES provider_profiles(id) ON DELETE RESTRICT,
                client_user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
                timezone TEXT NOT NULL CHECK(length(timezone) BETWEEN 1 AND 64),
                local_date TEXT NOT NULL CHECK(length(local_date) = 10 AND local_date BETWEEN '2000-01-01' AND '2100-12-31'
                    AND date(local_date, '+0 days') IS NOT NULL AND date(local_date, '+0 days') = local_date),
                local_start_minute INTEGER NOT NULL CHECK(typeof(local_start_minute) = 'integer' AND local_start_minute BETWEEN 0 AND 1379),
                starts_at_utc TEXT NOT NULL CHECK(length(starts_at_utc) = 19 AND substr(starts_at_utc, 18, 2) = '00'
                    AND strftime('%Y-%m-%d %H:%M:%S', starts_at_utc, '+0 seconds') IS NOT NULL
                    AND strftime('%Y-%m-%d %H:%M:%S', starts_at_utc, '+0 seconds') = starts_at_utc),
                ends_at_utc TEXT NOT NULL CHECK(length(ends_at_utc) = 19
                    AND strftime('%Y-%m-%d %H:%M:%S', ends_at_utc, '+0 seconds') IS NOT NULL
                    AND strftime('%Y-%m-%d %H:%M:%S', ends_at_utc, '+0 seconds') = ends_at_utc
                    AND unixepoch(ends_at_utc) - unixepoch(starts_at_utc) = 3600),
                availability_revision INTEGER NOT NULL CHECK(typeof(availability_revision) = 'integer' AND availability_revision >= 1),
                idempotency_key TEXT NOT NULL CHECK(length(idempotency_key) = 36 AND lower(idempotency_key) = idempotency_key),
                status TEXT NOT NULL DEFAULT 'requested' CHECK(status IN ('requested','confirmed','rejected','cancelled','completed')),
                version INTEGER NOT NULL DEFAULT 1 CHECK(typeof(version) = 'integer' AND version >= 1),
                last_actor_user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
                last_reason TEXT NOT NULL CHECK(last_reason IN ('proposal','confirm','reject','cancel','complete','inquiry_completed')),
                created_at TEXT NOT NULL CHECK(length(created_at) = 19 AND strftime('%Y-%m-%d %H:%M:%S', created_at, '+0 seconds') IS NOT NULL
                    AND strftime('%Y-%m-%d %H:%M:%S', created_at, '+0 seconds') = created_at AND created_at < starts_at_utc),
                updated_at TEXT NOT NULL CHECK(length(updated_at) = 19 AND strftime('%Y-%m-%d %H:%M:%S', updated_at, '+0 seconds') IS NOT NULL
                    AND strftime('%Y-%m-%d %H:%M:%S', updated_at, '+0 seconds') = updated_at AND updated_at >= created_at),
                UNIQUE(client_user_id, service_inquiry_id, idempotency_key)
            )
            SQL);
        DB::statement("CREATE UNIQUE INDEX reservations_one_active_inquiry ON service_reservations(service_inquiry_id) WHERE status IN ('requested','confirmed')");
        DB::statement("CREATE INDEX reservations_provider_occupancy ON service_reservations(provider_profile_id, starts_at_utc, ends_at_utc) WHERE status = 'confirmed'");
        DB::statement('CREATE INDEX reservations_client_list ON service_reservations(client_user_id, id)');
        DB::statement('CREATE INDEX reservations_provider_list ON service_reservations(provider_profile_id, id)');

        DB::statement(<<<'SQL'
            CREATE TABLE reservation_events (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                service_reservation_id INTEGER NOT NULL REFERENCES service_reservations(id) ON DELETE RESTRICT,
                version INTEGER NOT NULL CHECK(typeof(version) = 'integer' AND version >= 1),
                from_status TEXT CHECK(from_status IN ('requested','confirmed')),
                to_status TEXT NOT NULL CHECK(to_status IN ('requested','confirmed','rejected','cancelled','completed')),
                actor_user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
                reason TEXT NOT NULL CHECK(reason IN ('proposal','confirm','reject','cancel','complete','inquiry_completed')),
                occurred_at_utc TEXT NOT NULL,
                UNIQUE(service_reservation_id, version)
            )
            SQL);

        // La relación se deriva siempre de la solicitud y el servicio centrales, incluso en SQL directo.
        foreach (['INSERT', 'UPDATE'] as $operation) {
            $suffix = strtolower($operation);
            DB::unprepared("CREATE TRIGGER reservations_identity_{$suffix} BEFORE {$operation} ON service_reservations
                WHEN NOT EXISTS (SELECT 1 FROM service_inquiries i JOIN services s ON s.id = i.service_id
                    JOIN provider_profiles p ON p.id = s.provider_profile_id
                    WHERE i.id = NEW.service_inquiry_id AND i.service_id = NEW.service_id AND i.client_user_id = NEW.client_user_id
                    AND p.id = NEW.provider_profile_id AND p.user_id != NEW.client_user_id)
                BEGIN SELECT RAISE(ABORT, 'reservation_identity'); END");
            DB::unprepared("CREATE TRIGGER reservations_accepted_{$suffix} BEFORE {$operation} ON service_reservations
                WHEN NEW.status IN ('requested','confirmed') AND NOT EXISTS
                    (SELECT 1 FROM service_inquiries WHERE id = NEW.service_inquiry_id AND status = 'accepted')
                BEGIN SELECT RAISE(ABORT, 'reservation_inquiry_closed'); END");
            $exclude = $operation === 'UPDATE' ? 'AND r.id != OLD.id' : '';
            DB::unprepared("CREATE TRIGGER reservations_no_overlap_{$suffix} BEFORE {$operation} ON service_reservations
                WHEN NEW.status = 'confirmed' AND EXISTS
                    (SELECT 1 FROM service_reservations r WHERE r.provider_profile_id = NEW.provider_profile_id AND r.status = 'confirmed'
                     AND r.starts_at_utc < NEW.ends_at_utc AND r.ends_at_utc > NEW.starts_at_utc {$exclude})
                BEGIN SELECT RAISE(ABORT, 'reservation_overlap'); END");
        }

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER reservations_initial_state BEFORE INSERT ON service_reservations
            WHEN NEW.status != 'requested' OR NEW.version != 1 OR NEW.last_actor_user_id != NEW.client_user_id OR NEW.last_reason != 'proposal'
            BEGIN SELECT RAISE(ABORT, 'reservation_initial_state'); END;

            CREATE TRIGGER reservations_immutable BEFORE UPDATE ON service_reservations
            WHEN NEW.id IS NOT OLD.id OR NEW.service_inquiry_id IS NOT OLD.service_inquiry_id OR NEW.service_id IS NOT OLD.service_id
                OR NEW.provider_profile_id IS NOT OLD.provider_profile_id OR NEW.client_user_id IS NOT OLD.client_user_id
                OR NEW.timezone IS NOT OLD.timezone OR NEW.local_date IS NOT OLD.local_date OR NEW.local_start_minute IS NOT OLD.local_start_minute
                OR NEW.starts_at_utc IS NOT OLD.starts_at_utc OR NEW.ends_at_utc IS NOT OLD.ends_at_utc
                OR NEW.availability_revision IS NOT OLD.availability_revision OR NEW.idempotency_key IS NOT OLD.idempotency_key OR NEW.created_at IS NOT OLD.created_at
            BEGIN SELECT RAISE(ABORT, 'reservation_immutable'); END;

            CREATE TRIGGER reservations_transition BEFORE UPDATE ON service_reservations
            WHEN NEW.version != OLD.version + 1 OR NEW.updated_at < OLD.updated_at OR NOT (
                (OLD.status = 'requested' AND NEW.status = 'confirmed' AND NEW.last_reason = 'confirm' AND NEW.updated_at < NEW.starts_at_utc
                    AND NEW.last_actor_user_id = (SELECT user_id FROM provider_profiles WHERE id = NEW.provider_profile_id)) OR
                (OLD.status = 'requested' AND NEW.status = 'rejected' AND NEW.last_reason = 'reject'
                    AND NEW.last_actor_user_id = (SELECT user_id FROM provider_profiles WHERE id = NEW.provider_profile_id)) OR
                (OLD.status IN ('requested','confirmed') AND NEW.status = 'cancelled' AND NEW.last_reason = 'cancel'
                    AND (OLD.status = 'requested' OR NEW.updated_at < NEW.starts_at_utc)
                    AND (NEW.last_actor_user_id = NEW.client_user_id OR NEW.last_actor_user_id = (SELECT user_id FROM provider_profiles WHERE id = NEW.provider_profile_id))) OR
                (OLD.status = 'requested' AND NEW.status = 'cancelled' AND NEW.last_reason = 'inquiry_completed'
                    AND NEW.last_actor_user_id = (SELECT user_id FROM provider_profiles WHERE id = NEW.provider_profile_id)) OR
                (OLD.status = 'confirmed' AND NEW.status = 'completed' AND NEW.last_reason IN ('complete','inquiry_completed') AND NEW.updated_at >= NEW.ends_at_utc
                    AND NEW.last_actor_user_id = (SELECT user_id FROM provider_profiles WHERE id = NEW.provider_profile_id))
            )
            BEGIN SELECT RAISE(ABORT, 'reservation_transition'); END;

            CREATE TRIGGER reservations_history_insert AFTER INSERT ON service_reservations
            BEGIN INSERT INTO reservation_events(service_reservation_id, version, from_status, to_status, actor_user_id, reason, occurred_at_utc)
                VALUES(NEW.id, NEW.version, NULL, NEW.status, NEW.last_actor_user_id, NEW.last_reason, NEW.updated_at); END;

            CREATE TRIGGER reservations_history_update AFTER UPDATE ON service_reservations
            BEGIN INSERT INTO reservation_events(service_reservation_id, version, from_status, to_status, actor_user_id, reason, occurred_at_utc)
                VALUES(NEW.id, NEW.version, OLD.status, NEW.status, NEW.last_actor_user_id, NEW.last_reason, NEW.updated_at); END;

            CREATE TRIGGER reservations_no_delete BEFORE DELETE ON service_reservations
            BEGIN SELECT RAISE(ABORT, 'reservation_history_required'); END;
            CREATE TRIGGER reservation_events_no_update BEFORE UPDATE ON reservation_events
            BEGIN SELECT RAISE(ABORT, 'reservation_event_immutable'); END;
            CREATE TRIGGER reservation_events_no_delete BEFORE DELETE ON reservation_events
            BEGIN SELECT RAISE(ABORT, 'reservation_event_immutable'); END;
            CREATE TRIGGER reservation_events_consistent BEFORE INSERT ON reservation_events
            WHEN NOT EXISTS (SELECT 1 FROM service_reservations r WHERE r.id = NEW.service_reservation_id AND r.version = NEW.version
                AND r.status = NEW.to_status AND r.last_actor_user_id = NEW.actor_user_id AND r.last_reason = NEW.reason AND r.updated_at = NEW.occurred_at_utc)
            BEGIN SELECT RAISE(ABORT, 'reservation_event_inconsistent'); END;

            CREATE TRIGGER reservation_inquiry_closure BEFORE UPDATE OF status ON service_inquiries
            WHEN NEW.status != 'accepted' AND EXISTS (SELECT 1 FROM service_reservations WHERE service_inquiry_id = OLD.id AND status IN ('requested','confirmed'))
            BEGIN SELECT RAISE(ABORT, 'reservation_active_inquiry'); END;
            CREATE TRIGGER reservation_inquiry_identity BEFORE UPDATE OF service_id, client_user_id ON service_inquiries
            WHEN (NEW.service_id != OLD.service_id OR NEW.client_user_id != OLD.client_user_id) AND EXISTS (SELECT 1 FROM service_reservations WHERE service_inquiry_id = OLD.id)
            BEGIN SELECT RAISE(ABORT, 'reservation_identity_immutable'); END;
            CREATE TRIGGER reservation_service_identity BEFORE UPDATE OF provider_profile_id ON services
            WHEN NEW.provider_profile_id != OLD.provider_profile_id AND EXISTS (SELECT 1 FROM service_reservations WHERE service_id = OLD.id)
            BEGIN SELECT RAISE(ABORT, 'reservation_identity_immutable'); END;
            CREATE TRIGGER reservation_profile_identity BEFORE UPDATE OF user_id ON provider_profiles
            WHEN NEW.user_id != OLD.user_id AND EXISTS (SELECT 1 FROM service_reservations WHERE provider_profile_id = OLD.id)
            BEGIN SELECT RAISE(ABORT, 'reservation_identity_immutable'); END;
            SQL);
    }

    public function down(): void
    {
        foreach (['reservation_inquiry_closure', 'reservation_inquiry_identity', 'reservation_service_identity', 'reservation_profile_identity'] as $trigger) {
            DB::unprepared('DROP TRIGGER IF EXISTS '.$trigger);
        }
        DB::statement('DROP TABLE IF EXISTS reservation_events');
        DB::statement('DROP TABLE IF EXISTS service_reservations');
    }
};
