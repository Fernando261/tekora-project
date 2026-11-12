-- B2.2.1 TEST PROTOTYPE ONLY. Applied exclusively by SyntheticPricingDatabase::create().
-- No application migration, operational table, existing path or IF NOT EXISTS.
-- SQLite >= 3.38, PHP x64, foreign_keys=ON; caller owns BEGIN IMMEDIATE/COMMIT.
CREATE TABLE main.b221_fixture_users (
    id INTEGER NOT NULL PRIMARY KEY CHECK(id>0)
) STRICT;
CREATE TABLE main.b221_fixture_providers (
    id INTEGER NOT NULL PRIMARY KEY CHECK(id>0),
    user_id INTEGER NOT NULL UNIQUE REFERENCES b221_fixture_users(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    UNIQUE(id,user_id)
) STRICT;
CREATE TABLE main.b221_fixture_services (
    id INTEGER NOT NULL PRIMARY KEY CHECK(id>0),
    provider_profile_id INTEGER NOT NULL REFERENCES b221_fixture_providers(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    UNIQUE(id,provider_profile_id)
) STRICT;
CREATE TABLE main.b221_fixture_inquiries (
    id INTEGER NOT NULL PRIMARY KEY CHECK(id>0),
    service_id INTEGER NOT NULL REFERENCES b221_fixture_services(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    client_user_id INTEGER NOT NULL REFERENCES b221_fixture_users(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    UNIQUE(id,service_id,client_user_id)
) STRICT;

CREATE TABLE main.b221_price_versions (
    id TEXT COLLATE BINARY NOT NULL CHECK(length(id)=36 AND substr(id,9,1)='-' AND substr(id,14,1)='-' AND substr(id,19,1)='-' AND substr(id,24,1)='-' AND length(replace(id,'-',''))=32 AND replace(id,'-','') NOT GLOB '*[^0-9a-f]*'),
    service_id INTEGER NOT NULL CHECK(typeof(service_id)='integer' AND service_id BETWEEN 1 AND 9223372036854775807),
    provider_profile_id INTEGER NOT NULL CHECK(typeof(provider_profile_id)='integer' AND provider_profile_id BETWEEN 1 AND 9223372036854775807),
    version INTEGER NOT NULL CHECK(typeof(version)='integer' AND version BETWEEN 1 AND 1000000000),
    amount_cents INTEGER NOT NULL CHECK(typeof(amount_cents)='integer' AND amount_cents BETWEEN 1 AND 1000000000),
    currency TEXT NOT NULL CHECK(currency='MXN'),
    duration_minutes INTEGER NOT NULL CHECK(typeof(duration_minutes)='integer' AND duration_minutes=60),
    created_by INTEGER NOT NULL CHECK(typeof(created_by)='integer' AND created_by BETWEEN 1 AND 9223372036854775807),
    idempotency_key TEXT COLLATE BINARY NOT NULL CHECK(length(idempotency_key)=36 AND substr(idempotency_key,9,1)='-' AND substr(idempotency_key,14,1)='-' AND substr(idempotency_key,19,1)='-' AND substr(idempotency_key,24,1)='-' AND length(replace(idempotency_key,'-',''))=32 AND replace(idempotency_key,'-','') NOT GLOB '*[^0-9a-f]*'),
    created_at_utc TEXT COLLATE BINARY NOT NULL CHECK(length(created_at_utc)=26 AND substr(created_at_utc,20,1)='.' AND substr(created_at_utc,21,6) NOT GLOB '*[^0-9]*' AND strftime('%Y-%m-%d %H:%M:%S',substr(created_at_utc,1,19),'+0 seconds') IS NOT NULL AND strftime('%Y-%m-%d %H:%M:%S',substr(created_at_utc,1,19),'+0 seconds')=substr(created_at_utc,1,19) AND created_at_utc BETWEEN '2000-01-01 00:00:00.000000' AND '2100-12-31 23:59:59.999999'),
    PRIMARY KEY(id),
    UNIQUE(service_id,version),
    UNIQUE(service_id,idempotency_key),
    UNIQUE(id,service_id,provider_profile_id,version,amount_cents),
    FOREIGN KEY(service_id,provider_profile_id) REFERENCES b221_fixture_services(id,provider_profile_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    FOREIGN KEY(provider_profile_id,created_by) REFERENCES b221_fixture_providers(id,user_id) ON DELETE RESTRICT ON UPDATE RESTRICT
) STRICT;

CREATE TABLE main.b221_quotes (
    id TEXT COLLATE BINARY NOT NULL CHECK(length(id)=36 AND substr(id,9,1)='-' AND substr(id,14,1)='-' AND substr(id,19,1)='-' AND substr(id,24,1)='-' AND length(replace(id,'-',''))=32 AND replace(id,'-','') NOT GLOB '*[^0-9a-f]*'),
    service_inquiry_id INTEGER NOT NULL CHECK(typeof(service_inquiry_id)='integer' AND service_inquiry_id BETWEEN 1 AND 9223372036854775807),
    service_id INTEGER NOT NULL CHECK(typeof(service_id)='integer' AND service_id BETWEEN 1 AND 9223372036854775807),
    provider_profile_id INTEGER NOT NULL CHECK(typeof(provider_profile_id)='integer' AND provider_profile_id BETWEEN 1 AND 9223372036854775807),
    client_user_id INTEGER NOT NULL CHECK(typeof(client_user_id)='integer' AND client_user_id BETWEEN 1 AND 9223372036854775807),
    price_version_id TEXT COLLATE BINARY NOT NULL CHECK(length(price_version_id)=36 AND substr(price_version_id,9,1)='-' AND substr(price_version_id,14,1)='-' AND substr(price_version_id,19,1)='-' AND substr(price_version_id,24,1)='-' AND length(replace(price_version_id,'-',''))=32 AND replace(price_version_id,'-','') NOT GLOB '*[^0-9a-f]*'),
    price_version INTEGER NOT NULL CHECK(typeof(price_version)='integer' AND price_version BETWEEN 1 AND 1000000000),
    amount_cents INTEGER NOT NULL CHECK(typeof(amount_cents)='integer' AND amount_cents BETWEEN 1 AND 1000000000),
    commission_cents INTEGER NOT NULL CHECK(typeof(commission_cents)='integer' AND commission_cents BETWEEN 0 AND 1000000000),
    provider_cents INTEGER NOT NULL CHECK(typeof(provider_cents)='integer' AND provider_cents BETWEEN 0 AND 1000000000),
    currency TEXT NOT NULL CHECK(currency='MXN'),
    duration_minutes INTEGER NOT NULL CHECK(typeof(duration_minutes)='integer' AND duration_minutes=60),
    commission_bps INTEGER NOT NULL CHECK(typeof(commission_bps)='integer' AND commission_bps=1500),
    provider_bps INTEGER NOT NULL CHECK(typeof(provider_bps)='integer' AND provider_bps=8500),
    policy_version TEXT NOT NULL CHECK(policy_version='synthetic-commercial-v1-15pct-half-up'),
    synthetic INTEGER NOT NULL CHECK(typeof(synthetic)='integer' AND synthetic=1),
    idempotency_key TEXT COLLATE BINARY NOT NULL CHECK(length(idempotency_key)=36 AND substr(idempotency_key,9,1)='-' AND substr(idempotency_key,14,1)='-' AND substr(idempotency_key,19,1)='-' AND substr(idempotency_key,24,1)='-' AND length(replace(idempotency_key,'-',''))=32 AND replace(idempotency_key,'-','') NOT GLOB '*[^0-9a-f]*'),
    issued_at_utc TEXT COLLATE BINARY NOT NULL CHECK(length(issued_at_utc)=26 AND substr(issued_at_utc,20,1)='.' AND substr(issued_at_utc,21,6) NOT GLOB '*[^0-9]*' AND strftime('%Y-%m-%d %H:%M:%S',substr(issued_at_utc,1,19),'+0 seconds') IS NOT NULL AND strftime('%Y-%m-%d %H:%M:%S',substr(issued_at_utc,1,19),'+0 seconds')=substr(issued_at_utc,1,19) AND issued_at_utc BETWEEN '2000-01-01 00:00:00.000000' AND '2100-12-31 23:59:59.999999'),
    expires_at_utc TEXT COLLATE BINARY NOT NULL CHECK(length(expires_at_utc)=26 AND substr(expires_at_utc,20,1)='.' AND substr(expires_at_utc,21,6) NOT GLOB '*[^0-9]*' AND strftime('%Y-%m-%d %H:%M:%S',substr(expires_at_utc,1,19),'+0 seconds') IS NOT NULL AND strftime('%Y-%m-%d %H:%M:%S',substr(expires_at_utc,1,19),'+0 seconds')=substr(expires_at_utc,1,19) AND expires_at_utc BETWEEN '2000-01-01 00:00:00.000000' AND '2100-12-31 23:59:59.999999'),
    PRIMARY KEY(id),
    UNIQUE(client_user_id,idempotency_key),
    UNIQUE(id,client_user_id),
    CHECK(commission_cents+provider_cents=amount_cents),
    CHECK(commission_cents=(amount_cents/100)*15+(((amount_cents%100)*15+50)/100)),
    CHECK(expires_at_utc>=strftime('%Y-%m-%d %H:%M:%S',substr(issued_at_utc,1,19),'+1 second')||substr(issued_at_utc,20) AND expires_at_utc<=strftime('%Y-%m-%d %H:%M:%S',substr(issued_at_utc,1,19),'+1 day')||substr(issued_at_utc,20)),
    FOREIGN KEY(price_version_id,service_id,provider_profile_id,price_version,amount_cents) REFERENCES b221_price_versions(id,service_id,provider_profile_id,version,amount_cents) ON DELETE RESTRICT ON UPDATE RESTRICT,
    FOREIGN KEY(service_inquiry_id,service_id,client_user_id) REFERENCES b221_fixture_inquiries(id,service_id,client_user_id) ON DELETE RESTRICT ON UPDATE RESTRICT
) STRICT;

CREATE TABLE main.b221_acceptances (
    quote_id TEXT COLLATE BINARY NOT NULL CHECK(length(quote_id)=36 AND substr(quote_id,9,1)='-' AND substr(quote_id,14,1)='-' AND substr(quote_id,19,1)='-' AND substr(quote_id,24,1)='-' AND length(replace(quote_id,'-',''))=32 AND replace(quote_id,'-','') NOT GLOB '*[^0-9a-f]*'),
    client_user_id INTEGER NOT NULL CHECK(typeof(client_user_id)='integer' AND client_user_id BETWEEN 1 AND 9223372036854775807),
    accepted_at_utc TEXT COLLATE BINARY NOT NULL CHECK(length(accepted_at_utc)=26 AND substr(accepted_at_utc,20,1)='.' AND substr(accepted_at_utc,21,6) NOT GLOB '*[^0-9]*' AND strftime('%Y-%m-%d %H:%M:%S',substr(accepted_at_utc,1,19),'+0 seconds') IS NOT NULL AND strftime('%Y-%m-%d %H:%M:%S',substr(accepted_at_utc,1,19),'+0 seconds')=substr(accepted_at_utc,1,19) AND accepted_at_utc BETWEEN '2000-01-01 00:00:00.000000' AND '2100-12-31 23:59:59.999999'),
    PRIMARY KEY(quote_id),
    FOREIGN KEY(quote_id,client_user_id) REFERENCES b221_quotes(id,client_user_id) ON DELETE RESTRICT ON UPDATE RESTRICT
) STRICT;

CREATE TRIGGER main.b221_price_append BEFORE INSERT ON b221_price_versions
WHEN EXISTS(SELECT 1 FROM main.b221_price_versions WHERE id=NEW.id OR (service_id=NEW.service_id AND (version=NEW.version OR idempotency_key=NEW.idempotency_key)))
 OR NEW.version != 1+COALESCE((SELECT MAX(version) FROM main.b221_price_versions WHERE service_id=NEW.service_id),0)
BEGIN SELECT RAISE(ABORT,'b221_price_append'); END;

CREATE TRIGGER main.b221_quote_append BEFORE INSERT ON b221_quotes
WHEN EXISTS(SELECT 1 FROM main.b221_quotes WHERE id=NEW.id OR (client_user_id=NEW.client_user_id AND idempotency_key=NEW.idempotency_key))
 OR NOT EXISTS(SELECT 1 FROM main.b221_price_versions p JOIN main.b221_fixture_providers r ON r.id=p.provider_profile_id
     WHERE p.id=NEW.price_version_id AND p.created_at_utc<=NEW.issued_at_utc AND r.user_id!=NEW.client_user_id)
BEGIN SELECT RAISE(ABORT,'b221_quote_append'); END;

CREATE TRIGGER main.b221_acceptance_append BEFORE INSERT ON b221_acceptances
WHEN EXISTS(SELECT 1 FROM main.b221_acceptances WHERE quote_id=NEW.quote_id)
 OR NOT EXISTS(SELECT 1 FROM main.b221_quotes q WHERE q.id=NEW.quote_id AND q.client_user_id=NEW.client_user_id
     AND q.issued_at_utc<=NEW.accepted_at_utc AND NEW.accepted_at_utc<q.expires_at_utc)
BEGIN SELECT RAISE(ABORT,'b221_acceptance_append'); END;

CREATE INDEX main.b221_fixture_services_provider_profile_id ON b221_fixture_services(provider_profile_id);

CREATE INDEX main.b221_fixture_inquiries_service_id ON b221_fixture_inquiries(service_id);

CREATE INDEX main.b221_fixture_inquiries_client_user_id ON b221_fixture_inquiries(client_user_id);

CREATE INDEX main.b221_price_versions_provider_profile_id ON b221_price_versions(provider_profile_id);

CREATE INDEX main.b221_price_versions_created_by ON b221_price_versions(created_by);

CREATE INDEX main.b221_quotes_price_version_id ON b221_quotes(price_version_id);

CREATE INDEX main.b221_quotes_service_inquiry_id ON b221_quotes(service_inquiry_id);

CREATE INDEX main.b221_quotes_provider_profile_id ON b221_quotes(provider_profile_id);

CREATE INDEX main.b221_acceptances_client_user_id ON b221_acceptances(client_user_id);

CREATE TRIGGER main.b221_fixture_users_no_update BEFORE UPDATE ON b221_fixture_users
BEGIN SELECT RAISE(ABORT,'b221_history_immutable'); END;

CREATE TRIGGER main.b221_fixture_users_no_delete BEFORE DELETE ON b221_fixture_users
BEGIN SELECT RAISE(ABORT,'b221_history_immutable'); END;

CREATE TRIGGER main.b221_fixture_users_no_replace BEFORE INSERT ON b221_fixture_users
WHEN EXISTS(SELECT 1 FROM main.b221_fixture_users WHERE id=NEW.id)
BEGIN SELECT RAISE(ABORT,'b221_fixture_immutable'); END;

CREATE TRIGGER main.b221_fixture_providers_no_update BEFORE UPDATE ON b221_fixture_providers
BEGIN SELECT RAISE(ABORT,'b221_history_immutable'); END;

CREATE TRIGGER main.b221_fixture_providers_no_delete BEFORE DELETE ON b221_fixture_providers
BEGIN SELECT RAISE(ABORT,'b221_history_immutable'); END;

CREATE TRIGGER main.b221_fixture_providers_no_replace BEFORE INSERT ON b221_fixture_providers
WHEN EXISTS(SELECT 1 FROM main.b221_fixture_providers WHERE id=NEW.id)
BEGIN SELECT RAISE(ABORT,'b221_fixture_immutable'); END;

CREATE TRIGGER main.b221_fixture_services_no_update BEFORE UPDATE ON b221_fixture_services
BEGIN SELECT RAISE(ABORT,'b221_history_immutable'); END;

CREATE TRIGGER main.b221_fixture_services_no_delete BEFORE DELETE ON b221_fixture_services
BEGIN SELECT RAISE(ABORT,'b221_history_immutable'); END;

CREATE TRIGGER main.b221_fixture_services_no_replace BEFORE INSERT ON b221_fixture_services
WHEN EXISTS(SELECT 1 FROM main.b221_fixture_services WHERE id=NEW.id)
BEGIN SELECT RAISE(ABORT,'b221_fixture_immutable'); END;

CREATE TRIGGER main.b221_fixture_inquiries_no_update BEFORE UPDATE ON b221_fixture_inquiries
BEGIN SELECT RAISE(ABORT,'b221_history_immutable'); END;

CREATE TRIGGER main.b221_fixture_inquiries_no_delete BEFORE DELETE ON b221_fixture_inquiries
BEGIN SELECT RAISE(ABORT,'b221_history_immutable'); END;

CREATE TRIGGER main.b221_fixture_inquiries_no_replace BEFORE INSERT ON b221_fixture_inquiries
WHEN EXISTS(SELECT 1 FROM main.b221_fixture_inquiries WHERE id=NEW.id)
BEGIN SELECT RAISE(ABORT,'b221_fixture_immutable'); END;

CREATE TRIGGER main.b221_price_versions_no_update BEFORE UPDATE ON b221_price_versions
BEGIN SELECT RAISE(ABORT,'b221_history_immutable'); END;

CREATE TRIGGER main.b221_price_versions_no_delete BEFORE DELETE ON b221_price_versions
BEGIN SELECT RAISE(ABORT,'b221_history_immutable'); END;

CREATE TRIGGER main.b221_quotes_no_update BEFORE UPDATE ON b221_quotes
BEGIN SELECT RAISE(ABORT,'b221_history_immutable'); END;

CREATE TRIGGER main.b221_quotes_no_delete BEFORE DELETE ON b221_quotes
BEGIN SELECT RAISE(ABORT,'b221_history_immutable'); END;

CREATE TRIGGER main.b221_acceptances_no_update BEFORE UPDATE ON b221_acceptances
BEGIN SELECT RAISE(ABORT,'b221_history_immutable'); END;

CREATE TRIGGER main.b221_acceptances_no_delete BEFORE DELETE ON b221_acceptances
BEGIN SELECT RAISE(ABORT,'b221_history_immutable'); END;
