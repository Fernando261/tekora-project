<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite' || PHP_INT_SIZE !== 8) {
            throw new RuntimeException('Retenciones requiere SQLite y PHP de 64 bits.');
        }
        if ((int) DB::selectOne('PRAGMA foreign_keys')->foreign_keys !== 1) {
            throw new RuntimeException('Retenciones requiere FK habilitadas.');
        }
        if (DB::select('PRAGMA foreign_key_check') !== [] || DB::selectOne('PRAGMA integrity_check')->integrity_check !== 'ok') {
            throw new RuntimeException('No adaptar un esquema con integridad incierta.');
        }
        if (DB::selectOne('SELECT CAST(tekora_booking_now_us() AS INTEGER) AS n')->n <= 2147483647) {
            throw new RuntimeException('El reloj SQLite no preserva el entero de 64 bits.');
        }
        if (DB::selectOne("SELECT count(*) AS n FROM service_reservations a JOIN service_reservations b ON a.id<b.id AND a.provider_profile_id=b.provider_profile_id AND a.status='confirmed' AND b.status='confirmed' AND a.starts_at_utc<b.ends_at_utc AND a.ends_at_utc>b.starts_at_utc")->n !== 0) {
            throw new RuntimeException('Existen reservas confirmadas incompatibles; no continuar.');
        }
        DB::unprepared('DROP TRIGGER reservations_transition');
        DB::unprepared('DROP TRIGGER reservation_inquiry_closure');
        DB::unprepared('DROP TRIGGER reservations_no_overlap_insert');
        DB::unprepared('DROP TRIGGER reservations_no_overlap_update');
        DB::unprepared('DROP TRIGGER activity_reservation_recipient');
        DB::unprepared('DROP TRIGGER activity_reservation_event');
        DB::unprepared('DROP INDEX reservations_one_active_inquiry');
        DB::unprepared(<<<'SQL'

ALTER TABLE service_reservations ADD COLUMN quote_id TEXT REFERENCES service_quotes(id) ON DELETE RESTRICT;
CREATE TABLE reservation_hold_clock (id INTEGER PRIMARY KEY CHECK(id=1), high_water_us INTEGER NOT NULL CHECK(typeof(high_water_us)='integer' AND high_water_us>=0));
INSERT INTO reservation_hold_clock VALUES(1,0);
CREATE TABLE reservation_holds (
 service_reservation_id INTEGER PRIMARY KEY REFERENCES service_reservations(id) ON DELETE RESTRICT,
 accepted_by INTEGER NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 accepted_at_us INTEGER NOT NULL CHECK(typeof(accepted_at_us)='integer' AND accepted_at_us>0),
 expires_at_us INTEGER NOT NULL CHECK(typeof(expires_at_us)='integer' AND expires_at_us=accepted_at_us+900000000),
 state TEXT NOT NULL CHECK(state IN ('held','committed','released','expired')),
 version INTEGER NOT NULL CHECK(typeof(version)='integer' AND version>=1),
 last_actor_user_id INTEGER REFERENCES users(id) ON DELETE RESTRICT,
 closed_at_us INTEGER CHECK(closed_at_us IS NULL OR (typeof(closed_at_us)='integer' AND closed_at_us>=accepted_at_us)),
 CHECK((state='held' AND closed_at_us IS NULL) OR (state!='held' AND closed_at_us IS NOT NULL))
);
CREATE INDEX reservation_holds_expiry ON reservation_holds(state,expires_at_us);
CREATE TABLE reservation_hold_events (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 service_reservation_id INTEGER NOT NULL REFERENCES reservation_holds(service_reservation_id) ON DELETE RESTRICT,
 version INTEGER NOT NULL,
 from_state TEXT CHECK(from_state='held'),
 to_state TEXT NOT NULL CHECK(to_state IN ('held','committed','released','expired')),
 actor_user_id INTEGER REFERENCES users(id) ON DELETE RESTRICT,
 occurred_at_us INTEGER NOT NULL CHECK(typeof(occurred_at_us)='integer'),
 recorded_at_us INTEGER NOT NULL CHECK(typeof(recorded_at_us)='integer' AND recorded_at_us>=occurred_at_us),
 UNIQUE(service_reservation_id,version)
);
CREATE TABLE reservation_action_receipts (
 actor_user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 idempotency_key TEXT NOT NULL CHECK(length(idempotency_key)=36 AND idempotency_key=lower(idempotency_key)),
 service_reservation_id INTEGER NOT NULL REFERENCES service_reservations(id) ON DELETE RESTRICT,
 action TEXT NOT NULL CHECK(action IN ('confirm','commit','reject','cancel','complete')),
 expected_version INTEGER NOT NULL CHECK(typeof(expected_version)='integer' AND expected_version>=1),
 result_version INTEGER NOT NULL CHECK(typeof(result_version)='integer' AND result_version>=1),
 recorded_at_us INTEGER NOT NULL CHECK(typeof(recorded_at_us)='integer' AND recorded_at_us>0),
 PRIMARY KEY(actor_user_id,idempotency_key)
);
CREATE VIEW reservation_active_bookings AS
 SELECT r.id AS reservation_id,r.service_inquiry_id,r.provider_profile_id,r.starts_at_utc,r.ends_at_utc,r.status,h.state AS hold_state
 FROM service_reservations r LEFT JOIN reservation_holds h ON h.service_reservation_id=r.id
 WHERE r.status='confirmed' OR (r.status='requested' AND (h.state IS NULL OR (h.state='held' AND h.expires_at_us>max(CAST(tekora_booking_now_us() AS INTEGER),(SELECT high_water_us FROM reservation_hold_clock WHERE id=1)))));
CREATE VIEW reservation_occupancy AS
 SELECT reservation_id,provider_profile_id,starts_at_utc,ends_at_utc FROM reservation_active_bookings
 WHERE status='confirmed' OR hold_state='held';
CREATE TRIGGER hold_clock_monotonic BEFORE UPDATE ON reservation_hold_clock
 WHEN NEW.id IS NOT OLD.id OR NEW.high_water_us<OLD.high_water_us OR (NEW.high_water_us>OLD.high_water_us AND NEW.high_water_us>CAST(tekora_booking_now_us() AS INTEGER))
 BEGIN SELECT RAISE(ABORT,'reservation_clock_invalid'); END;
CREATE TRIGGER hold_clock_no_delete BEFORE DELETE ON reservation_hold_clock BEGIN SELECT RAISE(ABORT,'reservation_clock_required'); END;
CREATE TRIGGER reservation_quote_immutable BEFORE UPDATE OF quote_id ON service_reservations WHEN NEW.quote_id IS NOT OLD.quote_id
 BEGIN SELECT RAISE(ABORT,'reservation_quote_immutable'); END;
CREATE TRIGGER reservation_quote_binding BEFORE INSERT ON service_reservations BEGIN
 SELECT CASE WHEN EXISTS(SELECT 1 FROM service_price_versions WHERE service_id=NEW.service_id) AND NEW.quote_id IS NULL THEN RAISE(ABORT,'reservation_accepted_quote_required') END;
 SELECT CASE WHEN NEW.quote_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM service_quotes q JOIN quote_acceptances a ON a.quote_id=q.id WHERE q.id=NEW.quote_id AND q.service_inquiry_id=NEW.service_inquiry_id AND q.service_id=NEW.service_id AND q.client_user_id=NEW.client_user_id AND a.client_user_id=NEW.client_user_id AND q.provider_profile_id=NEW.provider_profile_id) THEN RAISE(ABORT,'reservation_quote_identity') END;
END;
CREATE TRIGGER hold_initial BEFORE INSERT ON reservation_holds BEGIN
 SELECT CASE WHEN NEW.state!='held' OR NEW.version!=1 OR NEW.last_actor_user_id IS NOT NEW.accepted_by OR NEW.closed_at_us IS NOT NULL THEN RAISE(ABORT,'reservation_hold_initial') END;
 SELECT CASE WHEN NEW.accepted_at_us<(SELECT high_water_us FROM reservation_hold_clock WHERE id=1) OR NEW.accepted_at_us>CAST(tekora_booking_now_us() AS INTEGER) OR NEW.expires_at_us<=max(CAST(tekora_booking_now_us() AS INTEGER),(SELECT high_water_us FROM reservation_hold_clock WHERE id=1)) THEN RAISE(ABORT,'reservation_hold_clock') END;
 SELECT CASE WHEN NOT EXISTS(SELECT 1 FROM service_reservations r JOIN provider_profiles p ON p.id=r.provider_profile_id JOIN quote_acceptances a ON a.quote_id=r.quote_id AND a.client_user_id=r.client_user_id JOIN service_inquiries i ON i.id=r.service_inquiry_id WHERE r.id=NEW.service_reservation_id AND r.status='requested' AND i.status='accepted' AND r.quote_id IS NOT NULL AND NEW.accepted_by=p.user_id AND unixepoch(r.starts_at_utc)*1000000>max(CAST(tekora_booking_now_us() AS INTEGER),(SELECT high_water_us FROM reservation_hold_clock WHERE id=1))) THEN RAISE(ABORT,'reservation_hold_identity') END;
 SELECT CASE WHEN EXISTS(SELECT 1 FROM reservation_occupancy o JOIN service_reservations r ON r.id=NEW.service_reservation_id WHERE o.provider_profile_id=r.provider_profile_id AND o.starts_at_utc<r.ends_at_utc AND o.ends_at_utc>r.starts_at_utc) THEN RAISE(ABORT,'reservation_overlap') END;
END;
CREATE TRIGGER hold_immutable BEFORE UPDATE ON reservation_holds
 WHEN NEW.service_reservation_id IS NOT OLD.service_reservation_id OR NEW.accepted_by IS NOT OLD.accepted_by OR NEW.accepted_at_us IS NOT OLD.accepted_at_us OR NEW.expires_at_us IS NOT OLD.expires_at_us
 BEGIN SELECT RAISE(ABORT,'reservation_hold_immutable'); END;
CREATE TRIGGER hold_transition BEFORE UPDATE ON reservation_holds WHEN OLD.state!='held' OR NEW.version!=OLD.version+1 OR NOT (
 (NEW.state='expired' AND NEW.expires_at_us<=max(CAST(tekora_booking_now_us() AS INTEGER),(SELECT high_water_us FROM reservation_hold_clock WHERE id=1)) AND NEW.last_actor_user_id IS NULL AND NEW.closed_at_us=NEW.expires_at_us) OR
 (NEW.state='committed' AND NEW.expires_at_us>max(CAST(tekora_booking_now_us() AS INTEGER),(SELECT high_water_us FROM reservation_hold_clock WHERE id=1)) AND NEW.closed_at_us>=NEW.accepted_at_us AND NEW.closed_at_us<=max(CAST(tekora_booking_now_us() AS INTEGER),(SELECT high_water_us FROM reservation_hold_clock WHERE id=1)) AND EXISTS(SELECT 1 FROM service_reservations r WHERE r.id=NEW.service_reservation_id AND r.status='confirmed' AND r.last_actor_user_id=r.client_user_id AND NEW.last_actor_user_id=r.client_user_id)) OR
 (NEW.state='released' AND NEW.expires_at_us>max(CAST(tekora_booking_now_us() AS INTEGER),(SELECT high_water_us FROM reservation_hold_clock WHERE id=1)) AND NEW.closed_at_us>=NEW.accepted_at_us AND NEW.closed_at_us<=max(CAST(tekora_booking_now_us() AS INTEGER),(SELECT high_water_us FROM reservation_hold_clock WHERE id=1)) AND EXISTS(SELECT 1 FROM service_reservations r WHERE r.id=NEW.service_reservation_id AND r.status='cancelled' AND NEW.last_actor_user_id=r.last_actor_user_id))
 ) BEGIN SELECT RAISE(ABORT,'reservation_hold_transition'); END;
CREATE TRIGGER hold_history_insert AFTER INSERT ON reservation_holds BEGIN
 UPDATE reservation_hold_clock SET high_water_us=CAST(tekora_booking_now_us() AS INTEGER) WHERE id=1;
 INSERT INTO reservation_hold_events(service_reservation_id,version,from_state,to_state,actor_user_id,occurred_at_us,recorded_at_us) VALUES(NEW.service_reservation_id,NEW.version,NULL,NEW.state,NEW.last_actor_user_id,NEW.accepted_at_us,CAST(tekora_booking_now_us() AS INTEGER));
END;
CREATE TRIGGER hold_history_update AFTER UPDATE ON reservation_holds BEGIN
 INSERT INTO reservation_hold_events(service_reservation_id,version,from_state,to_state,actor_user_id,occurred_at_us,recorded_at_us) VALUES(NEW.service_reservation_id,NEW.version,OLD.state,NEW.state,NEW.last_actor_user_id,NEW.closed_at_us,max(CAST(tekora_booking_now_us() AS INTEGER),(SELECT high_water_us FROM reservation_hold_clock WHERE id=1)));
END;
CREATE TRIGGER hold_events_consistent BEFORE INSERT ON reservation_hold_events WHEN NOT EXISTS(SELECT 1 FROM reservation_holds h WHERE h.service_reservation_id=NEW.service_reservation_id AND h.version=NEW.version AND h.state=NEW.to_state AND h.last_actor_user_id IS NEW.actor_user_id AND NEW.occurred_at_us=coalesce(h.closed_at_us,h.accepted_at_us))
 BEGIN SELECT RAISE(ABORT,'reservation_hold_event_inconsistent'); END;
CREATE TRIGGER hold_receipt_consistent BEFORE INSERT ON reservation_action_receipts WHEN NOT EXISTS(SELECT 1 FROM service_reservations r JOIN provider_profiles p ON p.id=r.provider_profile_id WHERE r.id=NEW.service_reservation_id AND r.quote_id IS NOT NULL AND r.version=NEW.result_version AND NEW.actor_user_id IN (r.client_user_id,p.user_id) AND ((NEW.action='confirm' AND r.status='requested' AND r.version=NEW.expected_version AND EXISTS(SELECT 1 FROM reservation_holds h WHERE h.service_reservation_id=r.id AND h.state='held' AND h.accepted_by=NEW.actor_user_id)) OR (NEW.action='commit' AND r.status='confirmed' AND r.last_actor_user_id=NEW.actor_user_id AND r.version=NEW.expected_version+1) OR (NEW.action IN ('cancel','reject','complete') AND r.last_reason=NEW.action AND r.last_actor_user_id=NEW.actor_user_id AND r.version=NEW.expected_version+1)))
 BEGIN SELECT RAISE(ABORT,'reservation_receipt_identity'); END;
CREATE TRIGGER hold_no_delete BEFORE DELETE ON reservation_holds BEGIN SELECT RAISE(ABORT,'reservation_hold_history_required'); END;
CREATE TRIGGER hold_reservation_hold_events_update BEFORE UPDATE ON reservation_hold_events BEGIN SELECT RAISE(ABORT,'reservation_hold_append_only'); END;
CREATE TRIGGER hold_reservation_hold_events_delete BEFORE DELETE ON reservation_hold_events BEGIN SELECT RAISE(ABORT,'reservation_hold_append_only'); END;
CREATE TRIGGER hold_reservation_action_receipts_update BEFORE UPDATE ON reservation_action_receipts BEGIN SELECT RAISE(ABORT,'reservation_hold_append_only'); END;
CREATE TRIGGER hold_reservation_action_receipts_delete BEFORE DELETE ON reservation_action_receipts BEGIN SELECT RAISE(ABORT,'reservation_hold_append_only'); END;

CREATE TRIGGER reservations_no_overlap_insert BEFORE INSERT ON service_reservations WHEN NEW.status='confirmed' AND EXISTS(SELECT 1 FROM reservation_occupancy o WHERE o.provider_profile_id=NEW.provider_profile_id AND o.starts_at_utc<NEW.ends_at_utc AND o.ends_at_utc>NEW.starts_at_utc ) BEGIN SELECT RAISE(ABORT,'reservation_overlap'); END;
CREATE TRIGGER hold_one_active_insert BEFORE INSERT ON service_reservations WHEN NEW.status IN ('requested','confirmed') AND EXISTS(SELECT 1 FROM reservation_active_bookings o WHERE o.service_inquiry_id=NEW.service_inquiry_id AND o.reservation_id!=NEW.id) BEGIN SELECT RAISE(ABORT,'reservation_one_active_inquiry'); END;
CREATE TRIGGER hold_clock_reservation_insert BEFORE INSERT ON service_reservations WHEN EXISTS(SELECT 1 FROM reservation_holds) BEGIN
 SELECT CASE WHEN CAST(tekora_booking_now_us() AS INTEGER)<(SELECT high_water_us FROM reservation_hold_clock WHERE id=1) THEN RAISE(ABORT,'reservation_clock_backwards') END;
 UPDATE reservation_hold_clock SET high_water_us=CAST(tekora_booking_now_us() AS INTEGER) WHERE id=1;
END;

CREATE TRIGGER reservations_no_overlap_update BEFORE UPDATE ON service_reservations WHEN NEW.status='confirmed' AND EXISTS(SELECT 1 FROM reservation_occupancy o WHERE o.provider_profile_id=NEW.provider_profile_id AND o.starts_at_utc<NEW.ends_at_utc AND o.ends_at_utc>NEW.starts_at_utc AND o.reservation_id!=OLD.id) BEGIN SELECT RAISE(ABORT,'reservation_overlap'); END;
CREATE TRIGGER hold_one_active_update BEFORE UPDATE ON service_reservations WHEN NEW.status IN ('requested','confirmed') AND EXISTS(SELECT 1 FROM reservation_active_bookings o WHERE o.service_inquiry_id=NEW.service_inquiry_id AND o.reservation_id!=NEW.id) BEGIN SELECT RAISE(ABORT,'reservation_one_active_inquiry'); END;
CREATE TRIGGER hold_clock_reservation_update BEFORE UPDATE ON service_reservations WHEN EXISTS(SELECT 1 FROM reservation_holds) BEGIN
 SELECT CASE WHEN CAST(tekora_booking_now_us() AS INTEGER)<(SELECT high_water_us FROM reservation_hold_clock WHERE id=1) THEN RAISE(ABORT,'reservation_clock_backwards') END;
 UPDATE reservation_hold_clock SET high_water_us=CAST(tekora_booking_now_us() AS INTEGER) WHERE id=1;
END;
CREATE TRIGGER reservations_transition BEFORE UPDATE ON service_reservations
            WHEN NEW.version != OLD.version + 1 OR NEW.updated_at < OLD.updated_at OR NOT (
                (OLD.status = 'requested' AND NEW.status = 'confirmed' AND NEW.last_reason = 'confirm' AND NEW.updated_at < NEW.starts_at_utc AND unixepoch(NEW.starts_at_utc)*1000000>max(CAST(tekora_booking_now_us() AS INTEGER),(SELECT high_water_us FROM reservation_hold_clock WHERE id=1))
                    AND ((NEW.quote_id IS NULL AND NEW.last_actor_user_id = (SELECT user_id FROM provider_profiles WHERE id = NEW.provider_profile_id)) OR (NEW.quote_id IS NOT NULL AND NEW.last_actor_user_id=NEW.client_user_id AND EXISTS(SELECT 1 FROM reservation_holds h WHERE h.service_reservation_id=NEW.id AND h.state='held' AND h.expires_at_us>max(CAST(tekora_booking_now_us() AS INTEGER),(SELECT high_water_us FROM reservation_hold_clock WHERE id=1)))))) OR
                (OLD.status = 'requested' AND NOT EXISTS(SELECT 1 FROM reservation_holds h WHERE h.service_reservation_id=OLD.id) AND NEW.status = 'rejected' AND NEW.last_reason = 'reject'
                    AND NEW.last_actor_user_id = (SELECT user_id FROM provider_profiles WHERE id = NEW.provider_profile_id)) OR
                (OLD.status IN ('requested','confirmed') AND NEW.status = 'cancelled' AND NEW.last_reason = 'cancel'
                    AND (OLD.status = 'requested' OR NEW.updated_at < NEW.starts_at_utc AND unixepoch(NEW.starts_at_utc)*1000000>max(CAST(tekora_booking_now_us() AS INTEGER),(SELECT high_water_us FROM reservation_hold_clock WHERE id=1)))
                    AND (NEW.last_actor_user_id = NEW.client_user_id OR NEW.last_actor_user_id = (SELECT user_id FROM provider_profiles WHERE id = NEW.provider_profile_id))) OR
                (OLD.status = 'requested' AND NEW.status = 'cancelled' AND NEW.last_reason = 'inquiry_completed'
                    AND NEW.last_actor_user_id = (SELECT user_id FROM provider_profiles WHERE id = NEW.provider_profile_id)) OR
                (OLD.status = 'confirmed' AND NEW.status = 'completed' AND NEW.last_reason IN ('complete','inquiry_completed') AND NEW.updated_at >= NEW.ends_at_utc AND unixepoch(NEW.ends_at_utc)*1000000<=max(CAST(tekora_booking_now_us() AS INTEGER),(SELECT high_water_us FROM reservation_hold_clock WHERE id=1))
                    AND NEW.last_actor_user_id = (SELECT user_id FROM provider_profiles WHERE id = NEW.provider_profile_id))
            )
            BEGIN SELECT RAISE(ABORT, 'reservation_transition'); END;

CREATE TRIGGER hold_reservation_terminal AFTER UPDATE OF status ON service_reservations WHEN NEW.status IN ('confirmed','cancelled') AND EXISTS(SELECT 1 FROM reservation_holds WHERE service_reservation_id=NEW.id AND state='held') BEGIN
 UPDATE reservation_holds SET state=CASE WHEN NEW.status='confirmed' THEN 'committed' WHEN expires_at_us<=max(CAST(tekora_booking_now_us() AS INTEGER),(SELECT high_water_us FROM reservation_hold_clock WHERE id=1)) THEN 'expired' ELSE 'released' END,
 version=version+1,last_actor_user_id=CASE WHEN NEW.status='confirmed' THEN NEW.last_actor_user_id WHEN expires_at_us<=max(CAST(tekora_booking_now_us() AS INTEGER),(SELECT high_water_us FROM reservation_hold_clock WHERE id=1)) THEN NULL ELSE NEW.last_actor_user_id END,
 closed_at_us=CASE WHEN NEW.status='confirmed' THEN CAST(tekora_booking_now_us() AS INTEGER) WHEN expires_at_us<=max(CAST(tekora_booking_now_us() AS INTEGER),(SELECT high_water_us FROM reservation_hold_clock WHERE id=1)) THEN expires_at_us ELSE CAST(tekora_booking_now_us() AS INTEGER) END WHERE service_reservation_id=NEW.id;
END;
CREATE TRIGGER reservation_inquiry_closure BEFORE UPDATE OF status ON service_inquiries WHEN NEW.status!='accepted' AND EXISTS(SELECT 1 FROM reservation_active_bookings WHERE service_inquiry_id=OLD.id) BEGIN SELECT RAISE(ABORT,'reservation_active_inquiry'); END;
CREATE TRIGGER activity_reservation_recipient BEFORE INSERT ON activity_notifications
            WHEN NEW.service_reservation_id IS NOT NULL AND NOT EXISTS (
                SELECT 1 FROM reservation_events e JOIN service_reservations r ON r.id = e.service_reservation_id
                JOIN provider_profiles p ON p.id = r.provider_profile_id
                WHERE r.id = NEW.service_reservation_id AND e.version = NEW.reservation_version
                AND NEW.event = 'reservation_' || e.to_status AND NEW.created_at = e.occurred_at_utc
                AND NEW.user_id != e.actor_user_id
                AND NEW.user_id = CASE WHEN e.to_status = 'requested' OR (e.to_status IN ('cancelled','confirmed') AND e.actor_user_id = r.client_user_id)
                    THEN p.user_id ELSE r.client_user_id END
            ) BEGIN SELECT RAISE(ABORT, 'activity_reservation_recipient'); END;
CREATE TRIGGER activity_reservation_event AFTER INSERT ON reservation_events
            BEGIN
                INSERT INTO activity_notifications(user_id, service_reservation_id, reservation_version, event, created_at)
                SELECT CASE WHEN NEW.to_status = 'requested' OR (NEW.to_status IN ('cancelled','confirmed') AND NEW.actor_user_id = r.client_user_id)
                        THEN p.user_id ELSE r.client_user_id END,
                    r.id, NEW.version, 'reservation_' || NEW.to_status, NEW.occurred_at_utc
                FROM service_reservations r JOIN provider_profiles p ON p.id = r.provider_profile_id
                WHERE r.id = NEW.service_reservation_id AND NEW.actor_user_id !=
                    CASE WHEN NEW.to_status = 'requested' OR (NEW.to_status IN ('cancelled','confirmed') AND NEW.actor_user_id = r.client_user_id)
                        THEN p.user_id ELSE r.client_user_id END;
            END;
SQL);
    }

    public function down(): void
    {
        if (DB::table('service_reservations')->whereNotNull('quote_id')->exists() || DB::table('reservation_holds')->exists()) {
            throw new RuntimeException('No revertir con propuestas comerciales o retenciones: se perdería su historial. Requiere revisión específica.');
        }
        DB::unprepared('DROP TRIGGER IF EXISTS hold_clock_monotonic');
        DB::unprepared('DROP TRIGGER IF EXISTS hold_clock_no_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS reservation_quote_immutable');
        DB::unprepared('DROP TRIGGER IF EXISTS reservation_quote_binding');
        DB::unprepared('DROP TRIGGER IF EXISTS hold_initial');
        DB::unprepared('DROP TRIGGER IF EXISTS hold_immutable');
        DB::unprepared('DROP TRIGGER IF EXISTS hold_transition');
        DB::unprepared('DROP TRIGGER IF EXISTS hold_history_insert');
        DB::unprepared('DROP TRIGGER IF EXISTS hold_history_update');
        DB::unprepared('DROP TRIGGER IF EXISTS hold_events_consistent');
        DB::unprepared('DROP TRIGGER IF EXISTS hold_receipt_consistent');
        DB::unprepared('DROP TRIGGER IF EXISTS hold_no_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS hold_reservation_hold_events_update');
        DB::unprepared('DROP TRIGGER IF EXISTS hold_reservation_hold_events_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS hold_reservation_action_receipts_update');
        DB::unprepared('DROP TRIGGER IF EXISTS hold_reservation_action_receipts_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS reservations_no_overlap_insert');
        DB::unprepared('DROP TRIGGER IF EXISTS hold_one_active_insert');
        DB::unprepared('DROP TRIGGER IF EXISTS hold_clock_reservation_insert');
        DB::unprepared('DROP TRIGGER IF EXISTS reservations_no_overlap_update');
        DB::unprepared('DROP TRIGGER IF EXISTS hold_one_active_update');
        DB::unprepared('DROP TRIGGER IF EXISTS hold_clock_reservation_update');
        DB::unprepared('DROP TRIGGER IF EXISTS reservations_transition');
        DB::unprepared('DROP TRIGGER IF EXISTS hold_reservation_terminal');
        DB::unprepared('DROP TRIGGER IF EXISTS reservation_inquiry_closure');
        DB::unprepared('DROP TRIGGER IF EXISTS activity_reservation_recipient');
        DB::unprepared('DROP TRIGGER IF EXISTS activity_reservation_event');
        DB::unprepared('DROP VIEW reservation_occupancy');
        DB::unprepared('DROP VIEW reservation_active_bookings');
        DB::unprepared('DROP TABLE reservation_action_receipts');
        DB::unprepared('DROP TABLE reservation_hold_events');
        DB::unprepared('DROP TABLE reservation_holds');
        DB::unprepared('DROP TABLE reservation_hold_clock');
        DB::unprepared('ALTER TABLE service_reservations DROP COLUMN quote_id');
        DB::unprepared(<<<'SQL'
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
CREATE TRIGGER reservation_inquiry_closure BEFORE UPDATE OF status ON service_inquiries
            WHEN NEW.status != 'accepted' AND EXISTS (SELECT 1 FROM service_reservations WHERE service_inquiry_id = OLD.id AND status IN ('requested','confirmed'))
            BEGIN SELECT RAISE(ABORT, 'reservation_active_inquiry'); END;
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
CREATE TRIGGER reservations_no_overlap_insert BEFORE INSERT ON service_reservations WHEN NEW.status='confirmed' AND EXISTS(SELECT 1 FROM service_reservations r WHERE r.provider_profile_id=NEW.provider_profile_id AND r.status='confirmed' AND r.starts_at_utc<NEW.ends_at_utc AND r.ends_at_utc>NEW.starts_at_utc ) BEGIN SELECT RAISE(ABORT,'reservation_overlap'); END;
CREATE TRIGGER reservations_no_overlap_update BEFORE UPDATE ON service_reservations WHEN NEW.status='confirmed' AND EXISTS(SELECT 1 FROM service_reservations r WHERE r.provider_profile_id=NEW.provider_profile_id AND r.status='confirmed' AND r.starts_at_utc<NEW.ends_at_utc AND r.ends_at_utc>NEW.starts_at_utc AND r.id != OLD.id) BEGIN SELECT RAISE(ABORT,'reservation_overlap'); END;
CREATE UNIQUE INDEX reservations_one_active_inquiry ON service_reservations(service_inquiry_id) WHERE status IN ('requested','confirmed');
SQL);
    }
};
