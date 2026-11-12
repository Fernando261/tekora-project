-- B2.2.2 TEST ONLY. Applied exclusively to a newly created synthetic database.
-- No Laravel migration, operational binding, purge, IF NOT EXISTS, TEMP or ATTACH.
CREATE TABLE main.b222_provider_identities (
 provider_profile_id INTEGER NOT NULL PRIMARY KEY CHECK(typeof(provider_profile_id)='integer' AND provider_profile_id BETWEEN 1 AND 9223372036854775807),
 provider_user_id INTEGER NOT NULL UNIQUE CHECK(typeof(provider_user_id)='integer' AND provider_user_id BETWEEN 1 AND 9223372036854775807),
 UNIQUE(provider_profile_id,provider_user_id)
) STRICT, WITHOUT ROWID;
CREATE TABLE main.b222_clock (
 singleton INTEGER NOT NULL PRIMARY KEY CHECK(singleton=1),
 last_observed_at_us INTEGER NOT NULL CHECK(typeof(last_observed_at_us)='integer' AND last_observed_at_us BETWEEN 946684800000000 AND 4133980799999999)
) STRICT, WITHOUT ROWID;
CREATE TABLE main.b222_holds (
 hold_id TEXT COLLATE BINARY NOT NULL PRIMARY KEY CHECK(typeof(hold_id)='text' AND instr(hold_id,char(0))=0 AND length(CAST(hold_id AS BLOB))=36 AND length(hold_id)=36 AND substr(hold_id,9,1)='-' AND substr(hold_id,14,1)='-' AND substr(hold_id,19,1)='-' AND substr(hold_id,24,1)='-' AND length(replace(hold_id,'-',''))=32 AND replace(hold_id,'-','') NOT GLOB '*[^0-9a-f]*'),
 reservation_id INTEGER NOT NULL UNIQUE CHECK(typeof(reservation_id)='integer' AND reservation_id BETWEEN 1 AND 9223372036854775807),
 proposal_version INTEGER NOT NULL CHECK(typeof(proposal_version)='integer' AND proposal_version BETWEEN 1 AND 9223372036854775807),
 quote_ref TEXT COLLATE BINARY NOT NULL UNIQUE CHECK(typeof(quote_ref)='text' AND instr(quote_ref,char(0))=0 AND length(CAST(quote_ref AS BLOB)) BETWEEN 1 AND 128 AND length(CAST(quote_ref AS BLOB))=length(quote_ref) AND quote_ref NOT GLOB '*[^a-zA-Z0-9_-]*'),
 inquiry_id INTEGER NOT NULL CHECK(typeof(inquiry_id)='integer' AND inquiry_id BETWEEN 1 AND 9223372036854775807),
 service_id INTEGER NOT NULL CHECK(typeof(service_id)='integer' AND service_id BETWEEN 1 AND 9223372036854775807),
 provider_profile_id INTEGER NOT NULL CHECK(typeof(provider_profile_id)='integer' AND provider_profile_id BETWEEN 1 AND 9223372036854775807),
 provider_user_id INTEGER NOT NULL CHECK(typeof(provider_user_id)='integer' AND provider_user_id BETWEEN 1 AND 9223372036854775807),
 client_user_id INTEGER NOT NULL CHECK(typeof(client_user_id)='integer' AND client_user_id BETWEEN 1 AND 9223372036854775807 AND client_user_id!=provider_user_id),
 starts_at_us INTEGER NOT NULL CHECK(typeof(starts_at_us)='integer' AND starts_at_us BETWEEN 946684800000000 AND 4133980799999999),
 ends_at_us INTEGER NOT NULL CHECK(typeof(ends_at_us)='integer' AND ends_at_us BETWEEN 946684800000000 AND 4133980799999999 AND ends_at_us=starts_at_us+3600000000),
 state TEXT NOT NULL CHECK(state IN ('held','committed','released','expired')),
 version INTEGER NOT NULL CHECK(typeof(version)='integer' AND version=CASE WHEN state='held' THEN 1 ELSE 2 END),
 accepted_at_us INTEGER NOT NULL CHECK(typeof(accepted_at_us)='integer' AND accepted_at_us BETWEEN 946684800000000 AND 4133980799999999),
 expires_at_us INTEGER NOT NULL CHECK(typeof(expires_at_us)='integer' AND expires_at_us BETWEEN 946684800000000 AND 4133980799999999 AND expires_at_us=accepted_at_us+900000000),
 updated_at_us INTEGER NOT NULL CHECK(typeof(updated_at_us)='integer' AND updated_at_us BETWEEN 946684800000000 AND 4133980799999999 AND updated_at_us>=accepted_at_us AND
   ((state='held' AND updated_at_us=accepted_at_us) OR (state IN ('committed','released') AND updated_at_us<expires_at_us) OR (state='expired' AND updated_at_us>=expires_at_us))),
 actor_kind TEXT NOT NULL CHECK(actor_kind IN ('provider','client','system')),
 actor_user_id INTEGER CHECK((actor_kind='system' AND actor_user_id IS NULL) OR (actor_kind!='system' AND typeof(actor_user_id)='integer' AND actor_user_id>0)),
 CHECK((state='held' AND actor_kind='provider' AND actor_user_id=provider_user_id) OR
       (state='released' AND ((actor_kind='provider' AND actor_user_id=provider_user_id) OR (actor_kind='client' AND actor_user_id=client_user_id))) OR
       (state IN ('committed','expired') AND actor_kind='system' AND actor_user_id IS NULL)),
 FOREIGN KEY(provider_profile_id,provider_user_id) REFERENCES b222_provider_identities(provider_profile_id,provider_user_id) ON DELETE RESTRICT ON UPDATE RESTRICT
) STRICT;
CREATE TABLE main.b222_hold_events (
 hold_id TEXT COLLATE BINARY NOT NULL CHECK(typeof(hold_id)='text' AND instr(hold_id,char(0))=0 AND length(CAST(hold_id AS BLOB))=36 AND length(hold_id)=36 AND substr(hold_id,9,1)='-' AND substr(hold_id,14,1)='-' AND substr(hold_id,19,1)='-' AND substr(hold_id,24,1)='-' AND length(replace(hold_id,'-',''))=32 AND replace(hold_id,'-','') NOT GLOB '*[^0-9a-f]*'),
 version INTEGER NOT NULL CHECK(typeof(version)='integer' AND version IN (1,2)),
 from_state TEXT CHECK(from_state IS NULL OR from_state='held'),
 to_state TEXT NOT NULL CHECK(to_state IN ('held','committed','released','expired')),
 actor_kind TEXT NOT NULL CHECK(actor_kind IN ('provider','client','system')),
 actor_user_id INTEGER CHECK((actor_kind='system' AND actor_user_id IS NULL) OR (actor_kind!='system' AND typeof(actor_user_id)='integer' AND actor_user_id>0)),
 actor_scope TEXT COLLATE BINARY NOT NULL CHECK(actor_scope=CASE WHEN actor_kind='system' THEN 'system:system' ELSE actor_kind||':'||CAST(actor_user_id AS TEXT) END),
 occurred_at_us INTEGER NOT NULL CHECK(typeof(occurred_at_us)='integer' AND occurred_at_us BETWEEN 946684800000000 AND 4133980799999999),
 PRIMARY KEY(hold_id,version),
 UNIQUE(hold_id,version,actor_scope,occurred_at_us),
 CHECK((version=1 AND from_state IS NULL AND to_state='held') OR (version=2 AND from_state='held' AND to_state!='held')),
 FOREIGN KEY(hold_id) REFERENCES b222_holds(hold_id) ON DELETE RESTRICT ON UPDATE RESTRICT
) STRICT;
CREATE TABLE main.b222_hold_receipts (
 scope_key TEXT COLLATE BINARY NOT NULL PRIMARY KEY CHECK(instr(scope_key,char(0))=0 AND scope_key=actor_scope||':'||intention_uuid),
 actor_scope TEXT COLLATE BINARY NOT NULL,
 intention_uuid TEXT COLLATE BINARY NOT NULL CHECK(typeof(intention_uuid)='text' AND instr(intention_uuid,char(0))=0 AND length(CAST(intention_uuid AS BLOB))=36 AND length(intention_uuid)=36 AND substr(intention_uuid,9,1)='-' AND substr(intention_uuid,14,1)='-' AND substr(intention_uuid,19,1)='-' AND substr(intention_uuid,24,1)='-' AND length(replace(intention_uuid,'-',''))=32 AND replace(intention_uuid,'-','') NOT GLOB '*[^0-9a-f]*'),
 fingerprint TEXT COLLATE BINARY NOT NULL CHECK(typeof(fingerprint)='text' AND instr(fingerprint,char(0))=0 AND length(CAST(fingerprint AS BLOB))=64 AND length(fingerprint)=64 AND fingerprint NOT GLOB '*[^0-9a-f]*'),
 hold_id TEXT COLLATE BINARY NOT NULL CHECK(typeof(hold_id)='text' AND instr(hold_id,char(0))=0 AND length(CAST(hold_id AS BLOB))=36 AND length(hold_id)=36 AND substr(hold_id,9,1)='-' AND substr(hold_id,14,1)='-' AND substr(hold_id,19,1)='-' AND substr(hold_id,24,1)='-' AND length(replace(hold_id,'-',''))=32 AND replace(hold_id,'-','') NOT GLOB '*[^0-9a-f]*'),
 event_version INTEGER NOT NULL CHECK(typeof(event_version)='integer' AND event_version IN (1,2)),
 recorded_at_us INTEGER NOT NULL CHECK(typeof(recorded_at_us)='integer' AND recorded_at_us BETWEEN 946684800000000 AND 4133980799999999),
 UNIQUE(actor_scope,intention_uuid),
 FOREIGN KEY(hold_id,event_version,actor_scope,recorded_at_us) REFERENCES b222_hold_events(hold_id,version,actor_scope,occurred_at_us) ON DELETE RESTRICT ON UPDATE RESTRICT
) STRICT;

CREATE INDEX main.b222_provider_blocks ON b222_holds(provider_profile_id,starts_at_us,ends_at_us) WHERE state IN ('held','committed');
CREATE INDEX main.b222_due ON b222_holds(expires_at_us,hold_id) WHERE state='held';
CREATE INDEX main.b222_receipt_hold ON b222_hold_receipts(hold_id,event_version);
CREATE TRIGGER main.b222_identity_insert BEFORE INSERT ON b222_provider_identities
 WHEN EXISTS(SELECT 1 FROM main.b222_provider_identities WHERE provider_profile_id=NEW.provider_profile_id OR provider_user_id=NEW.provider_user_id)
 BEGIN SELECT RAISE(ABORT,'provider_identity_conflict'); END;
CREATE TRIGGER main.b222_clock_insert BEFORE INSERT ON b222_clock
 WHEN EXISTS(SELECT 1 FROM main.b222_clock WHERE singleton=NEW.singleton)
 BEGIN SELECT RAISE(ABORT,'clock_replace'); END;
CREATE TRIGGER main.b222_clock_update BEFORE UPDATE ON b222_clock
 WHEN NEW.singleton IS NOT OLD.singleton OR NEW.last_observed_at_us<OLD.last_observed_at_us
 BEGIN SELECT RAISE(ABORT,'clock_regressed'); END;
CREATE TRIGGER main.b222_hold_insert BEFORE INSERT ON b222_holds
 WHEN NEW.state!='held' OR NEW.version!=1 OR EXISTS(SELECT 1 FROM main.b222_holds WHERE hold_id=NEW.hold_id OR reservation_id=NEW.reservation_id OR quote_ref=NEW.quote_ref)
 BEGIN SELECT RAISE(ABORT,'invalid_insert'); END;
CREATE TRIGGER main.b222_hold_transition BEFORE UPDATE ON b222_holds
 WHEN OLD.state!='held' OR OLD.version!=1 OR NEW.version!=2 OR NEW.state='held' OR NEW.updated_at_us<OLD.updated_at_us
 BEGIN SELECT RAISE(ABORT,'invalid_transition'); END;
CREATE TRIGGER main.b222_hold_immutable BEFORE UPDATE ON b222_holds
 WHEN NEW.hold_id IS NOT OLD.hold_id OR NEW.reservation_id IS NOT OLD.reservation_id OR NEW.proposal_version IS NOT OLD.proposal_version OR NEW.quote_ref IS NOT OLD.quote_ref OR NEW.inquiry_id IS NOT OLD.inquiry_id OR NEW.service_id IS NOT OLD.service_id OR NEW.provider_profile_id IS NOT OLD.provider_profile_id OR NEW.provider_user_id IS NOT OLD.provider_user_id OR NEW.client_user_id IS NOT OLD.client_user_id OR NEW.starts_at_us IS NOT OLD.starts_at_us OR NEW.ends_at_us IS NOT OLD.ends_at_us OR NEW.accepted_at_us IS NOT OLD.accepted_at_us OR NEW.expires_at_us IS NOT OLD.expires_at_us
 BEGIN SELECT RAISE(ABORT,'immutable_binding'); END;
CREATE TRIGGER main.b222_hold_overlap_insert BEFORE INSERT ON b222_holds
 WHEN NEW.state IN ('held','committed') AND EXISTS(SELECT 1 FROM main.b222_holds h WHERE h.provider_profile_id=NEW.provider_profile_id AND h.state IN ('held','committed') AND h.starts_at_us<NEW.ends_at_us AND h.ends_at_us>NEW.starts_at_us)
 BEGIN SELECT RAISE(ABORT,'slot_conflict'); END;
CREATE TRIGGER main.b222_hold_clock_insert BEFORE INSERT ON b222_holds
 WHEN NEW.updated_at_us IS NOT (SELECT last_observed_at_us FROM main.b222_clock WHERE singleton=1)
 BEGIN SELECT RAISE(ABORT,'invalid_clock'); END;
CREATE TRIGGER main.b222_hold_overlap_update BEFORE UPDATE ON b222_holds
 WHEN NEW.state IN ('held','committed') AND EXISTS(SELECT 1 FROM main.b222_holds h WHERE h.provider_profile_id=NEW.provider_profile_id AND h.state IN ('held','committed') AND h.starts_at_us<NEW.ends_at_us AND h.ends_at_us>NEW.starts_at_us AND h.hold_id!=OLD.hold_id)
 BEGIN SELECT RAISE(ABORT,'slot_conflict'); END;
CREATE TRIGGER main.b222_hold_clock_update BEFORE UPDATE ON b222_holds
 WHEN NEW.updated_at_us IS NOT (SELECT last_observed_at_us FROM main.b222_clock WHERE singleton=1)
 BEGIN SELECT RAISE(ABORT,'invalid_clock'); END;
CREATE TRIGGER main.b222_event_consistent BEFORE INSERT ON b222_hold_events
 WHEN EXISTS(SELECT 1 FROM main.b222_hold_events WHERE hold_id=NEW.hold_id AND version=NEW.version)
 OR NOT EXISTS(SELECT 1 FROM main.b222_holds h WHERE h.hold_id=NEW.hold_id AND h.version=NEW.version AND h.state=NEW.to_state AND h.updated_at_us=NEW.occurred_at_us AND h.actor_kind=NEW.actor_kind AND h.actor_user_id IS NEW.actor_user_id)
 OR (NEW.version=1 AND EXISTS(SELECT 1 FROM main.b222_hold_events WHERE hold_id=NEW.hold_id))
 OR (NEW.version=2 AND (SELECT COUNT(*) FROM main.b222_hold_events WHERE hold_id=NEW.hold_id AND version=1 AND to_state='held')!=1)
 BEGIN SELECT RAISE(ABORT,'invalid_history'); END;
CREATE TRIGGER main.b222_history_insert AFTER INSERT ON b222_holds
 BEGIN INSERT INTO b222_hold_events VALUES(NEW.hold_id,NEW.version,NULL,NEW.state,NEW.actor_kind,NEW.actor_user_id,CASE WHEN NEW.actor_kind='system' THEN 'system:system' ELSE NEW.actor_kind||':'||CAST(NEW.actor_user_id AS TEXT) END,NEW.updated_at_us); END;
CREATE TRIGGER main.b222_history_update AFTER UPDATE ON b222_holds
 BEGIN INSERT INTO b222_hold_events VALUES(NEW.hold_id,NEW.version,OLD.state,NEW.state,NEW.actor_kind,NEW.actor_user_id,CASE WHEN NEW.actor_kind='system' THEN 'system:system' ELSE NEW.actor_kind||':'||CAST(NEW.actor_user_id AS TEXT) END,NEW.updated_at_us); END;
CREATE TRIGGER main.b222_receipt_insert BEFORE INSERT ON b222_hold_receipts
 WHEN EXISTS(SELECT 1 FROM main.b222_hold_receipts WHERE scope_key=NEW.scope_key OR (actor_scope=NEW.actor_scope AND intention_uuid=NEW.intention_uuid))
 OR NOT EXISTS(SELECT 1 FROM main.b222_hold_events WHERE hold_id=NEW.hold_id AND version=NEW.event_version AND to_state IN ('held','released','committed'))
 BEGIN SELECT RAISE(ABORT,'invalid_receipt'); END;
CREATE TRIGGER main.b222_provider_identities_no_update BEFORE UPDATE ON b222_provider_identities BEGIN SELECT RAISE(ABORT,'history_immutable'); END;
CREATE TRIGGER main.b222_hold_events_no_update BEFORE UPDATE ON b222_hold_events BEGIN SELECT RAISE(ABORT,'history_immutable'); END;
CREATE TRIGGER main.b222_hold_receipts_no_update BEFORE UPDATE ON b222_hold_receipts BEGIN SELECT RAISE(ABORT,'history_immutable'); END;
CREATE TRIGGER main.b222_provider_identities_no_delete BEFORE DELETE ON b222_provider_identities BEGIN SELECT RAISE(ABORT,'history_immutable'); END;
CREATE TRIGGER main.b222_holds_no_delete BEFORE DELETE ON b222_holds BEGIN SELECT RAISE(ABORT,'history_immutable'); END;
CREATE TRIGGER main.b222_hold_events_no_delete BEFORE DELETE ON b222_hold_events BEGIN SELECT RAISE(ABORT,'history_immutable'); END;
CREATE TRIGGER main.b222_hold_receipts_no_delete BEFORE DELETE ON b222_hold_receipts BEGIN SELECT RAISE(ABORT,'history_immutable'); END;
CREATE TRIGGER main.b222_clock_no_delete BEFORE DELETE ON b222_clock BEGIN SELECT RAISE(ABORT,'history_immutable'); END;
