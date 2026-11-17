<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            throw new RuntimeException('Los precios web requieren SQLite; no ejecutar en otro motor.');
        }
        if ((int) DB::selectOne('PRAGMA foreign_keys')->foreign_keys !== 1) {
            throw new RuntimeException('Los precios web requieren claves foráneas habilitadas.');
        }
        DB::unprepared(<<<'SQL'

CREATE TABLE pricing_write_lock (id INTEGER PRIMARY KEY CHECK(id=1));
INSERT INTO pricing_write_lock(id) VALUES(1);
CREATE TABLE service_price_versions (
 id TEXT NOT NULL CHECK(length(id)=36 AND id=lower(id) AND substr(id,9,1)='-' AND substr(id,14,1)='-' AND substr(id,19,1)='-' AND substr(id,24,1)='-' AND length(replace(id,'-',''))=32 AND replace(id,'-','') NOT GLOB '*[^0-9a-f]*') PRIMARY KEY,
 service_id INTEGER NOT NULL REFERENCES services(id) ON DELETE RESTRICT,
 provider_profile_id INTEGER NOT NULL REFERENCES provider_profiles(id) ON DELETE RESTRICT,
 version INTEGER NOT NULL CHECK(typeof(version)='integer' AND version BETWEEN 1 AND 999999999),
 created_by INTEGER NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 idempotency_key TEXT NOT NULL CHECK(length(idempotency_key)=36 AND idempotency_key=lower(idempotency_key) AND substr(idempotency_key,9,1)='-' AND substr(idempotency_key,14,1)='-' AND substr(idempotency_key,19,1)='-' AND substr(idempotency_key,24,1)='-' AND length(replace(idempotency_key,'-',''))=32 AND replace(idempotency_key,'-','') NOT GLOB '*[^0-9a-f]*'),
 created_at_utc TEXT NOT NULL CHECK(length(created_at_utc)=26 AND strftime('%Y-%m-%d %H:%M:%S',created_at_utc) IS NOT NULL AND substr(created_at_utc,1,19)=strftime('%Y-%m-%d %H:%M:%S',created_at_utc) AND substr(created_at_utc,20,1)='.' AND substr(created_at_utc,21,6) NOT GLOB '*[^0-9]*'),

 total_cents INTEGER NOT NULL CHECK(typeof(total_cents)='integer' AND total_cents BETWEEN 1 AND 1000000000),
 commission_cents INTEGER NOT NULL CHECK(typeof(commission_cents)='integer' AND commission_cents = (total_cents / 100) * 15 + ((total_cents % 100) * 15 + 50) / 100),
 provider_cents INTEGER NOT NULL CHECK(typeof(provider_cents)='integer' AND provider_cents = total_cents - commission_cents),
 currency TEXT NOT NULL CHECK(currency='MXN'),
 duration_minutes INTEGER NOT NULL CHECK(duration_minutes=60),
 policy_version TEXT NOT NULL CHECK(policy_version='synthetic-commercial-v1-15pct-half-up'),
 commission_basis_points INTEGER NOT NULL CHECK(commission_basis_points=1500),
 provider_basis_points INTEGER NOT NULL CHECK(provider_basis_points=8500),

 UNIQUE(service_id,version), UNIQUE(service_id,idempotency_key)
);
CREATE TABLE service_quotes (
 id TEXT NOT NULL CHECK(length(id)=36 AND id=lower(id) AND substr(id,9,1)='-' AND substr(id,14,1)='-' AND substr(id,19,1)='-' AND substr(id,24,1)='-' AND length(replace(id,'-',''))=32 AND replace(id,'-','') NOT GLOB '*[^0-9a-f]*') PRIMARY KEY,
 service_inquiry_id INTEGER NOT NULL REFERENCES service_inquiries(id) ON DELETE RESTRICT,
 service_id INTEGER NOT NULL REFERENCES services(id) ON DELETE RESTRICT,
 provider_profile_id INTEGER NOT NULL REFERENCES provider_profiles(id) ON DELETE RESTRICT,
 client_user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 price_version_id TEXT NOT NULL REFERENCES service_price_versions(id) ON DELETE RESTRICT,
 price_version INTEGER NOT NULL,
 idempotency_key TEXT NOT NULL CHECK(length(idempotency_key)=36 AND idempotency_key=lower(idempotency_key) AND substr(idempotency_key,9,1)='-' AND substr(idempotency_key,14,1)='-' AND substr(idempotency_key,19,1)='-' AND substr(idempotency_key,24,1)='-' AND length(replace(idempotency_key,'-',''))=32 AND replace(idempotency_key,'-','') NOT GLOB '*[^0-9a-f]*'),
 issued_at_utc TEXT NOT NULL CHECK(length(issued_at_utc)=26 AND strftime('%Y-%m-%d %H:%M:%S',issued_at_utc) IS NOT NULL AND substr(issued_at_utc,1,19)=strftime('%Y-%m-%d %H:%M:%S',issued_at_utc) AND substr(issued_at_utc,20,1)='.' AND substr(issued_at_utc,21,6) NOT GLOB '*[^0-9]*'),
 expires_at_utc TEXT NOT NULL CHECK(length(expires_at_utc)=26 AND strftime('%Y-%m-%d %H:%M:%S',expires_at_utc) IS NOT NULL AND substr(expires_at_utc,1,19)=strftime('%Y-%m-%d %H:%M:%S',expires_at_utc) AND substr(expires_at_utc,20,1)='.' AND substr(expires_at_utc,21,6) NOT GLOB '*[^0-9]*'),

 total_cents INTEGER NOT NULL CHECK(typeof(total_cents)='integer' AND total_cents BETWEEN 1 AND 1000000000),
 commission_cents INTEGER NOT NULL CHECK(typeof(commission_cents)='integer' AND commission_cents = (total_cents / 100) * 15 + ((total_cents % 100) * 15 + 50) / 100),
 provider_cents INTEGER NOT NULL CHECK(typeof(provider_cents)='integer' AND provider_cents = total_cents - commission_cents),
 currency TEXT NOT NULL CHECK(currency='MXN'),
 duration_minutes INTEGER NOT NULL CHECK(duration_minutes=60),
 policy_version TEXT NOT NULL CHECK(policy_version='synthetic-commercial-v1-15pct-half-up'),
 commission_basis_points INTEGER NOT NULL CHECK(commission_basis_points=1500),
 provider_basis_points INTEGER NOT NULL CHECK(provider_basis_points=8500),

 CHECK(expires_at_utc > issued_at_utc AND julianday(expires_at_utc)-julianday(issued_at_utc) <= 1.00000001),
 UNIQUE(client_user_id,idempotency_key), UNIQUE(id,client_user_id)
);
CREATE INDEX service_quotes_inquiry ON service_quotes(service_inquiry_id,issued_at_utc,id);
CREATE TABLE quote_acceptances (
 quote_id TEXT PRIMARY KEY NOT NULL,
 client_user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 accepted_at_utc TEXT NOT NULL CHECK(length(accepted_at_utc)=26 AND strftime('%Y-%m-%d %H:%M:%S',accepted_at_utc) IS NOT NULL AND substr(accepted_at_utc,1,19)=strftime('%Y-%m-%d %H:%M:%S',accepted_at_utc) AND substr(accepted_at_utc,20,1)='.' AND substr(accepted_at_utc,21,6) NOT GLOB '*[^0-9]*'),
 FOREIGN KEY(quote_id,client_user_id) REFERENCES service_quotes(id,client_user_id) ON DELETE RESTRICT
);
CREATE TABLE service_pricing_creations (
 actor_id INTEGER NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 idempotency_key TEXT NOT NULL CHECK(length(idempotency_key)=36 AND idempotency_key=lower(idempotency_key) AND substr(idempotency_key,9,1)='-' AND substr(idempotency_key,14,1)='-' AND substr(idempotency_key,19,1)='-' AND substr(idempotency_key,24,1)='-' AND length(replace(idempotency_key,'-',''))=32 AND replace(idempotency_key,'-','') NOT GLOB '*[^0-9a-f]*'),
 service_id INTEGER NOT NULL UNIQUE REFERENCES services(id) ON DELETE RESTRICT,
 price_version_id TEXT NOT NULL UNIQUE REFERENCES service_price_versions(id) ON DELETE RESTRICT,
 fingerprint TEXT NOT NULL CHECK(length(fingerprint)=64 AND fingerprint NOT GLOB '*[^0-9a-f]*'),
 PRIMARY KEY(actor_id,idempotency_key)
);
CREATE TRIGGER pricing_price_identity BEFORE INSERT ON service_price_versions BEGIN
 SELECT CASE WHEN NOT EXISTS(SELECT 1 FROM services s JOIN provider_profiles p ON p.id=s.provider_profile_id WHERE s.id=NEW.service_id AND p.id=NEW.provider_profile_id AND p.user_id=NEW.created_by) THEN RAISE(ABORT,'pricing identity') END;
 SELECT CASE WHEN NEW.version != (SELECT coalesce(max(version),0)+1 FROM service_price_versions WHERE service_id=NEW.service_id) THEN RAISE(ABORT,'pricing sequence') END;
END;
CREATE TRIGGER pricing_quote_snapshot BEFORE INSERT ON service_quotes BEGIN
 SELECT CASE WHEN NOT EXISTS(SELECT 1 FROM service_inquiries i JOIN services s ON s.id=i.service_id JOIN provider_profiles p ON p.id=s.provider_profile_id WHERE i.id=NEW.service_inquiry_id AND i.service_id=NEW.service_id AND i.client_user_id=NEW.client_user_id AND s.provider_profile_id=NEW.provider_profile_id AND p.user_id != NEW.client_user_id AND i.status='accepted') THEN RAISE(ABORT,'quote identity or state') END;
 SELECT CASE WHEN NOT EXISTS(SELECT 1 FROM service_price_versions p WHERE p.id=NEW.price_version_id AND p.service_id=NEW.service_id AND p.provider_profile_id=NEW.provider_profile_id AND p.version=NEW.price_version AND p.total_cents=NEW.total_cents AND p.commission_cents=NEW.commission_cents AND p.provider_cents=NEW.provider_cents AND p.created_at_utc <= NEW.issued_at_utc) THEN RAISE(ABORT,'quote snapshot') END;
END;
CREATE TRIGGER pricing_acceptance_valid BEFORE INSERT ON quote_acceptances BEGIN
 SELECT CASE WHEN NOT EXISTS(SELECT 1 FROM service_quotes q JOIN service_inquiries i ON i.id=q.service_inquiry_id WHERE q.id=NEW.quote_id AND q.client_user_id=NEW.client_user_id AND NEW.accepted_at_utc >= q.issued_at_utc AND NEW.accepted_at_utc < q.expires_at_utc AND i.status='accepted') THEN RAISE(ABORT,'acceptance expired or identity') END;
END;
CREATE TRIGGER pricing_creation_valid BEFORE INSERT ON service_pricing_creations BEGIN
 SELECT CASE WHEN NOT EXISTS(SELECT 1 FROM service_price_versions p WHERE p.id=NEW.price_version_id AND p.service_id=NEW.service_id AND p.created_by=NEW.actor_id AND p.version=1) THEN RAISE(ABORT,'creation identity') END;
END;
CREATE TRIGGER pricing_service_owner BEFORE UPDATE OF provider_profile_id ON services WHEN NEW.provider_profile_id != OLD.provider_profile_id AND EXISTS(SELECT 1 FROM service_price_versions WHERE service_id=OLD.id) BEGIN SELECT RAISE(ABORT,'priced service owner immutable'); END;
CREATE TRIGGER pricing_profile_owner BEFORE UPDATE OF user_id ON provider_profiles WHEN NEW.user_id != OLD.user_id AND EXISTS(SELECT 1 FROM service_price_versions WHERE provider_profile_id=OLD.id) BEGIN SELECT RAISE(ABORT,'priced profile owner immutable'); END;
CREATE TRIGGER pricing_inquiry_identity BEFORE UPDATE OF service_id,client_user_id ON service_inquiries WHEN (NEW.service_id != OLD.service_id OR NEW.client_user_id != OLD.client_user_id) AND EXISTS(SELECT 1 FROM service_quotes WHERE service_inquiry_id=OLD.id) BEGIN SELECT RAISE(ABORT,'quoted inquiry identity immutable'); END;
CREATE TRIGGER pricing_service_price_versions_update BEFORE UPDATE ON service_price_versions BEGIN SELECT RAISE(ABORT,'pricing append only'); END;
CREATE TRIGGER pricing_service_price_versions_delete BEFORE DELETE ON service_price_versions BEGIN SELECT RAISE(ABORT,'pricing append only'); END;
CREATE TRIGGER pricing_service_quotes_update BEFORE UPDATE ON service_quotes BEGIN SELECT RAISE(ABORT,'pricing append only'); END;
CREATE TRIGGER pricing_service_quotes_delete BEFORE DELETE ON service_quotes BEGIN SELECT RAISE(ABORT,'pricing append only'); END;
CREATE TRIGGER pricing_quote_acceptances_update BEFORE UPDATE ON quote_acceptances BEGIN SELECT RAISE(ABORT,'pricing append only'); END;
CREATE TRIGGER pricing_quote_acceptances_delete BEFORE DELETE ON quote_acceptances BEGIN SELECT RAISE(ABORT,'pricing append only'); END;
CREATE TRIGGER pricing_service_pricing_creations_update BEFORE UPDATE ON service_pricing_creations BEGIN SELECT RAISE(ABORT,'pricing append only'); END;
CREATE TRIGGER pricing_service_pricing_creations_delete BEFORE DELETE ON service_pricing_creations BEGIN SELECT RAISE(ABORT,'pricing append only'); END;
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS pricing_service_owner');
        DB::unprepared('DROP TRIGGER IF EXISTS pricing_profile_owner');
        DB::unprepared('DROP TRIGGER IF EXISTS pricing_inquiry_identity');
        DB::unprepared('DROP TABLE IF EXISTS service_pricing_creations');
        DB::unprepared('DROP TABLE IF EXISTS quote_acceptances');
        DB::unprepared('DROP TABLE IF EXISTS service_quotes');
        DB::unprepared('DROP TABLE IF EXISTS service_price_versions');
        DB::unprepared('DROP TABLE IF EXISTS pricing_write_lock');
    }
};
