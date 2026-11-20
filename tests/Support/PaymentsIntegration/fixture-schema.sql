-- B2.3.1 synthetic fixture ONLY. Never loaded by Laravel migrations.
PRAGMA user_version=231;

CREATE TABLE b231_commands(
 id TEXT NOT NULL CHECK(typeof(id)='text' AND length(CAST(id AS BLOB))=36 AND instr(id,char(0))=0 AND id=lower(id) AND substr(id,9,1)='-' AND substr(id,14,1)='-' AND substr(id,19,1)='-' AND substr(id,24,1)='-' AND length(replace(id,'-',''))=32 AND replace(id,'-','') NOT GLOB '*[^0-9a-f]*'),
 namespace TEXT NOT NULL CHECK(namespace IN ('price','quote','proposal','hold','resolve','legacy')),
 scope TEXT NOT NULL CHECK(length(CAST(scope AS BLOB)) BETWEEN 1 AND 128 AND instr(scope,char(0))=0),
 intent_key TEXT NOT NULL CHECK(typeof(intent_key)='text' AND length(CAST(intent_key AS BLOB))=36 AND instr(intent_key,char(0))=0 AND intent_key=lower(intent_key) AND substr(intent_key,9,1)='-' AND substr(intent_key,14,1)='-' AND substr(intent_key,19,1)='-' AND substr(intent_key,24,1)='-' AND length(replace(intent_key,'-',''))=32 AND replace(intent_key,'-','') NOT GLOB '*[^0-9a-f]*'),
 fingerprint TEXT NOT NULL CHECK(length(CAST(fingerprint AS BLOB))=64 AND fingerprint NOT GLOB '*[^0-9a-f]*'),
 action TEXT NOT NULL,
 outcome TEXT NOT NULL CHECK(outcome IN ('success','rejected')),
 result_json TEXT NOT NULL CHECK(json_valid(result_json)),
 at_us INTEGER NOT NULL CHECK(at_us BETWEEN 946684800000000 AND 4133980799999999),
 actor_kind TEXT NOT NULL CHECK(actor_kind IN ('provider','client','system')),
 actor_user_id INTEGER,
 CHECK((actor_kind='system' AND actor_user_id IS NULL) OR (actor_kind IN ('provider','client') AND actor_user_id>0)),
 PRIMARY KEY(id),
 UNIQUE(namespace,scope,intent_key),
 FOREIGN KEY(actor_user_id) REFERENCES b231_users(id) DEFERRABLE INITIALLY DEFERRED
) STRICT, WITHOUT ROWID;

CREATE TRIGGER b231_commands_no_replace BEFORE INSERT ON b231_commands WHEN EXISTS(SELECT 1 FROM b231_commands WHERE id=NEW.id) BEGIN SELECT RAISE(ABORT,'identity collision'); END;

CREATE TRIGGER b231_commands_immutable BEFORE UPDATE ON b231_commands BEGIN SELECT RAISE(ABORT,'append only'); END;

CREATE TRIGGER b231_commands_no_delete BEFORE DELETE ON b231_commands BEGIN SELECT RAISE(ABORT,'append only'); END;

CREATE TRIGGER b231_commands_alternate BEFORE INSERT ON b231_commands WHEN EXISTS(SELECT 1 FROM b231_commands WHERE namespace=NEW.namespace AND scope=NEW.scope AND intent_key=NEW.intent_key) BEGIN SELECT RAISE(ABORT,'alternate identity collision'); END;

CREATE TABLE b231_users(
 id INTEGER NOT NULL CHECK(id>0),
 PRIMARY KEY(id)
) STRICT, WITHOUT ROWID;

CREATE TRIGGER b231_users_no_replace BEFORE INSERT ON b231_users WHEN EXISTS(SELECT 1 FROM b231_users WHERE id=NEW.id) BEGIN SELECT RAISE(ABORT,'identity collision'); END;

CREATE TRIGGER b231_users_immutable BEFORE UPDATE ON b231_users BEGIN SELECT RAISE(ABORT,'append only'); END;

CREATE TRIGGER b231_users_no_delete BEFORE DELETE ON b231_users BEGIN SELECT RAISE(ABORT,'append only'); END;

CREATE TABLE b231_providers(
 id INTEGER NOT NULL CHECK(id>0),
 user_id INTEGER NOT NULL CHECK(user_id>0),
 PRIMARY KEY(id),
 UNIQUE(user_id),
 UNIQUE(id,user_id),
 FOREIGN KEY(user_id) REFERENCES b231_users(id) DEFERRABLE INITIALLY DEFERRED
) STRICT, WITHOUT ROWID;

CREATE TRIGGER b231_providers_no_replace BEFORE INSERT ON b231_providers WHEN EXISTS(SELECT 1 FROM b231_providers WHERE id=NEW.id) BEGIN SELECT RAISE(ABORT,'identity collision'); END;

CREATE TRIGGER b231_providers_immutable BEFORE UPDATE ON b231_providers BEGIN SELECT RAISE(ABORT,'append only'); END;

CREATE TRIGGER b231_providers_no_delete BEFORE DELETE ON b231_providers BEGIN SELECT RAISE(ABORT,'append only'); END;

CREATE TRIGGER b231_providers_alternate BEFORE INSERT ON b231_providers WHEN EXISTS(SELECT 1 FROM b231_providers WHERE user_id=NEW.user_id) BEGIN SELECT RAISE(ABORT,'alternate identity collision'); END;

CREATE TABLE b231_services(
 id INTEGER NOT NULL CHECK(id>0),
 provider_id INTEGER NOT NULL CHECK(provider_id>0),
 PRIMARY KEY(id),
 UNIQUE(id,provider_id),
 FOREIGN KEY(provider_id) REFERENCES b231_providers(id) DEFERRABLE INITIALLY DEFERRED
) STRICT, WITHOUT ROWID;

CREATE TRIGGER b231_services_no_replace BEFORE INSERT ON b231_services WHEN EXISTS(SELECT 1 FROM b231_services WHERE id=NEW.id) BEGIN SELECT RAISE(ABORT,'identity collision'); END;

CREATE TRIGGER b231_services_immutable BEFORE UPDATE ON b231_services BEGIN SELECT RAISE(ABORT,'append only'); END;

CREATE TRIGGER b231_services_no_delete BEFORE DELETE ON b231_services BEGIN SELECT RAISE(ABORT,'append only'); END;

CREATE TABLE b231_inquiries(
 id INTEGER NOT NULL CHECK(id>0),
 service_id INTEGER NOT NULL CHECK(service_id>0),
 provider_id INTEGER NOT NULL CHECK(provider_id>0),
 client_id INTEGER NOT NULL CHECK(client_id>0),
 status TEXT NOT NULL CHECK(status='accepted'),
 PRIMARY KEY(id),
 UNIQUE(id,service_id,provider_id,client_id),
 FOREIGN KEY(service_id,provider_id) REFERENCES b231_services(id,provider_id) DEFERRABLE INITIALLY DEFERRED,
 FOREIGN KEY(client_id) REFERENCES b231_users(id) DEFERRABLE INITIALLY DEFERRED
) STRICT, WITHOUT ROWID;

CREATE TRIGGER b231_inquiries_no_replace BEFORE INSERT ON b231_inquiries WHEN EXISTS(SELECT 1 FROM b231_inquiries WHERE id=NEW.id) BEGIN SELECT RAISE(ABORT,'identity collision'); END;

CREATE TRIGGER b231_inquiries_immutable BEFORE UPDATE ON b231_inquiries BEGIN SELECT RAISE(ABORT,'append only'); END;

CREATE TRIGGER b231_inquiries_no_delete BEFORE DELETE ON b231_inquiries BEGIN SELECT RAISE(ABORT,'append only'); END;

CREATE TABLE b231_prices(
 id TEXT NOT NULL CHECK(typeof(id)='text' AND length(CAST(id AS BLOB))=36 AND instr(id,char(0))=0 AND id=lower(id) AND substr(id,9,1)='-' AND substr(id,14,1)='-' AND substr(id,19,1)='-' AND substr(id,24,1)='-' AND length(replace(id,'-',''))=32 AND replace(id,'-','') NOT GLOB '*[^0-9a-f]*'),
 service_id INTEGER NOT NULL CHECK(service_id>0),
 provider_profile_id INTEGER NOT NULL CHECK(provider_profile_id>0),
 version INTEGER NOT NULL CHECK(version BETWEEN 1 AND 1000000000),
 amount_cents INTEGER NOT NULL CHECK(amount_cents BETWEEN 1 AND 1000000000),
 currency TEXT NOT NULL CHECK(currency='MXN'),
 duration_minutes INTEGER NOT NULL CHECK(duration_minutes=60),
 created_by INTEGER NOT NULL CHECK(created_by>0),
 idempotency_key TEXT NOT NULL CHECK(typeof(idempotency_key)='text' AND length(CAST(idempotency_key AS BLOB))=36 AND instr(idempotency_key,char(0))=0 AND idempotency_key=lower(idempotency_key) AND substr(idempotency_key,9,1)='-' AND substr(idempotency_key,14,1)='-' AND substr(idempotency_key,19,1)='-' AND substr(idempotency_key,24,1)='-' AND length(replace(idempotency_key,'-',''))=32 AND replace(idempotency_key,'-','') NOT GLOB '*[^0-9a-f]*'),
 created_at_utc TEXT NOT NULL CHECK(length(CAST(created_at_utc AS BLOB))=26 AND instr(created_at_utc,char(0))=0 AND created_at_utc GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9] [0-9][0-9]:[0-9][0-9]:[0-9][0-9].[0-9][0-9][0-9][0-9][0-9][0-9]' AND created_at_utc BETWEEN '2000-01-01 00:00:00.000000' AND '2100-12-31 23:59:59.999999' AND strftime('%Y-%m-%d %H:%M:%S',substr(created_at_utc,1,19),'+0 seconds') IS substr(created_at_utc,1,19)),
 command_id TEXT NOT NULL CHECK(typeof(command_id)='text' AND length(CAST(command_id AS BLOB))=36 AND instr(command_id,char(0))=0 AND command_id=lower(command_id) AND substr(command_id,9,1)='-' AND substr(command_id,14,1)='-' AND substr(command_id,19,1)='-' AND substr(command_id,24,1)='-' AND length(replace(command_id,'-',''))=32 AND replace(command_id,'-','') NOT GLOB '*[^0-9a-f]*'),
 PRIMARY KEY(id),
 UNIQUE(service_id,version),
 UNIQUE(service_id,idempotency_key),
 UNIQUE(id,service_id,provider_profile_id,version,amount_cents),
 FOREIGN KEY(service_id,provider_profile_id) REFERENCES b231_services(id,provider_id) DEFERRABLE INITIALLY DEFERRED,
 FOREIGN KEY(provider_profile_id,created_by) REFERENCES b231_providers(id,user_id) DEFERRABLE INITIALLY DEFERRED,
 FOREIGN KEY(command_id) REFERENCES b231_commands(id) DEFERRABLE INITIALLY DEFERRED
) STRICT, WITHOUT ROWID;

CREATE TRIGGER b231_prices_no_replace BEFORE INSERT ON b231_prices WHEN EXISTS(SELECT 1 FROM b231_prices WHERE id=NEW.id) BEGIN SELECT RAISE(ABORT,'identity collision'); END;

CREATE TRIGGER b231_prices_immutable BEFORE UPDATE ON b231_prices BEGIN SELECT RAISE(ABORT,'append only'); END;

CREATE TRIGGER b231_prices_no_delete BEFORE DELETE ON b231_prices BEGIN SELECT RAISE(ABORT,'append only'); END;

CREATE TRIGGER b231_prices_unsealed BEFORE INSERT ON b231_prices WHEN EXISTS(SELECT 1 FROM b231_commands WHERE id=NEW.command_id) BEGIN SELECT RAISE(ABORT,'command sealed'); END;

CREATE TRIGGER b231_prices_alternate BEFORE INSERT ON b231_prices WHEN EXISTS(SELECT 1 FROM b231_prices WHERE (service_id=NEW.service_id AND (version=NEW.version OR idempotency_key=NEW.idempotency_key))) BEGIN SELECT RAISE(ABORT,'alternate identity collision'); END;

CREATE TRIGGER b231_price_version BEFORE INSERT ON b231_prices WHEN NEW.version<>coalesce((SELECT max(version)+1 FROM b231_prices WHERE service_id=NEW.service_id),1) BEGIN SELECT RAISE(ABORT,'price version'); END;

CREATE TABLE b231_quotes(
 id TEXT NOT NULL CHECK(typeof(id)='text' AND length(CAST(id AS BLOB))=36 AND instr(id,char(0))=0 AND id=lower(id) AND substr(id,9,1)='-' AND substr(id,14,1)='-' AND substr(id,19,1)='-' AND substr(id,24,1)='-' AND length(replace(id,'-',''))=32 AND replace(id,'-','') NOT GLOB '*[^0-9a-f]*'),
 service_inquiry_id INTEGER NOT NULL CHECK(service_inquiry_id>0),
 service_id INTEGER NOT NULL CHECK(service_id>0),
 provider_profile_id INTEGER NOT NULL CHECK(provider_profile_id>0),
 client_user_id INTEGER NOT NULL CHECK(client_user_id>0),
 price_version_id TEXT NOT NULL CHECK(typeof(price_version_id)='text' AND length(CAST(price_version_id AS BLOB))=36 AND instr(price_version_id,char(0))=0 AND price_version_id=lower(price_version_id) AND substr(price_version_id,9,1)='-' AND substr(price_version_id,14,1)='-' AND substr(price_version_id,19,1)='-' AND substr(price_version_id,24,1)='-' AND length(replace(price_version_id,'-',''))=32 AND replace(price_version_id,'-','') NOT GLOB '*[^0-9a-f]*'),
 price_version INTEGER NOT NULL CHECK(price_version>0),
 amount_cents INTEGER NOT NULL CHECK(amount_cents BETWEEN 1 AND 1000000000),
 commission_cents INTEGER NOT NULL CHECK(commission_cents=(amount_cents/100)*15+((amount_cents%100)*15+50)/100),
 provider_cents INTEGER NOT NULL CHECK(provider_cents=amount_cents-commission_cents),
 currency TEXT NOT NULL CHECK(currency='MXN'),
 duration_minutes INTEGER NOT NULL CHECK(duration_minutes=60),
 commission_bps INTEGER NOT NULL CHECK(commission_bps=1500),
 provider_bps INTEGER NOT NULL CHECK(provider_bps=8500),
 policy_version TEXT NOT NULL CHECK(policy_version='synthetic-commercial-v1-15pct-half-up'),
 synthetic INTEGER NOT NULL CHECK(synthetic=1),
 idempotency_key TEXT NOT NULL CHECK(typeof(idempotency_key)='text' AND length(CAST(idempotency_key AS BLOB))=36 AND instr(idempotency_key,char(0))=0 AND idempotency_key=lower(idempotency_key) AND substr(idempotency_key,9,1)='-' AND substr(idempotency_key,14,1)='-' AND substr(idempotency_key,19,1)='-' AND substr(idempotency_key,24,1)='-' AND length(replace(idempotency_key,'-',''))=32 AND replace(idempotency_key,'-','') NOT GLOB '*[^0-9a-f]*'),
 issued_at_utc TEXT NOT NULL CHECK(length(CAST(issued_at_utc AS BLOB))=26 AND instr(issued_at_utc,char(0))=0 AND issued_at_utc GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9] [0-9][0-9]:[0-9][0-9]:[0-9][0-9].[0-9][0-9][0-9][0-9][0-9][0-9]' AND issued_at_utc BETWEEN '2000-01-01 00:00:00.000000' AND '2100-12-31 23:59:59.999999' AND strftime('%Y-%m-%d %H:%M:%S',substr(issued_at_utc,1,19),'+0 seconds') IS substr(issued_at_utc,1,19)),
 expires_at_utc TEXT NOT NULL CHECK(length(CAST(expires_at_utc AS BLOB))=26 AND instr(expires_at_utc,char(0))=0 AND expires_at_utc GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9] [0-9][0-9]:[0-9][0-9]:[0-9][0-9].[0-9][0-9][0-9][0-9][0-9][0-9]' AND expires_at_utc BETWEEN '2000-01-01 00:00:00.000000' AND '2100-12-31 23:59:59.999999' AND strftime('%Y-%m-%d %H:%M:%S',substr(expires_at_utc,1,19),'+0 seconds') IS substr(expires_at_utc,1,19)),
 command_id TEXT NOT NULL CHECK(typeof(command_id)='text' AND length(CAST(command_id AS BLOB))=36 AND instr(command_id,char(0))=0 AND command_id=lower(command_id) AND substr(command_id,9,1)='-' AND substr(command_id,14,1)='-' AND substr(command_id,19,1)='-' AND substr(command_id,24,1)='-' AND length(replace(command_id,'-',''))=32 AND replace(command_id,'-','') NOT GLOB '*[^0-9a-f]*'),
 CHECK(substr(expires_at_utc,21,6)=substr(issued_at_utc,21,6) AND strftime('%s',substr(expires_at_utc,1,19))-strftime('%s',substr(issued_at_utc,1,19))=1800),
 PRIMARY KEY(id),
 UNIQUE(client_user_id,idempotency_key),
 UNIQUE(id,client_user_id),
 UNIQUE(id,service_inquiry_id,service_id,provider_profile_id,client_user_id),
 FOREIGN KEY(price_version_id,service_id,provider_profile_id,price_version,amount_cents) REFERENCES b231_prices(id,service_id,provider_profile_id,version,amount_cents) DEFERRABLE INITIALLY DEFERRED,
 FOREIGN KEY(service_inquiry_id,service_id,provider_profile_id,client_user_id) REFERENCES b231_inquiries(id,service_id,provider_id,client_id) DEFERRABLE INITIALLY DEFERRED,
 FOREIGN KEY(command_id) REFERENCES b231_commands(id) DEFERRABLE INITIALLY DEFERRED
) STRICT, WITHOUT ROWID;

CREATE TRIGGER b231_quotes_no_replace BEFORE INSERT ON b231_quotes WHEN EXISTS(SELECT 1 FROM b231_quotes WHERE id=NEW.id) BEGIN SELECT RAISE(ABORT,'identity collision'); END;

CREATE TRIGGER b231_quotes_immutable BEFORE UPDATE ON b231_quotes BEGIN SELECT RAISE(ABORT,'append only'); END;

CREATE TRIGGER b231_quotes_no_delete BEFORE DELETE ON b231_quotes BEGIN SELECT RAISE(ABORT,'append only'); END;

CREATE TRIGGER b231_quotes_unsealed BEFORE INSERT ON b231_quotes WHEN EXISTS(SELECT 1 FROM b231_commands WHERE id=NEW.command_id) BEGIN SELECT RAISE(ABORT,'command sealed'); END;

CREATE TRIGGER b231_quotes_alternate BEFORE INSERT ON b231_quotes WHEN EXISTS(SELECT 1 FROM b231_quotes WHERE client_user_id=NEW.client_user_id AND idempotency_key=NEW.idempotency_key) BEGIN SELECT RAISE(ABORT,'alternate identity collision'); END;

CREATE TRIGGER b231_quote_time BEFORE INSERT ON b231_quotes WHEN NEW.issued_at_utc<(SELECT created_at_utc FROM b231_prices WHERE id=NEW.price_version_id) BEGIN SELECT RAISE(ABORT,'quote time'); END;

CREATE TABLE b231_acceptances(
 quote_id TEXT NOT NULL CHECK(typeof(quote_id)='text' AND length(CAST(quote_id AS BLOB))=36 AND instr(quote_id,char(0))=0 AND quote_id=lower(quote_id) AND substr(quote_id,9,1)='-' AND substr(quote_id,14,1)='-' AND substr(quote_id,19,1)='-' AND substr(quote_id,24,1)='-' AND length(replace(quote_id,'-',''))=32 AND replace(quote_id,'-','') NOT GLOB '*[^0-9a-f]*'),
 client_user_id INTEGER NOT NULL CHECK(client_user_id>0),
 accepted_at_utc TEXT NOT NULL CHECK(length(CAST(accepted_at_utc AS BLOB))=26 AND instr(accepted_at_utc,char(0))=0 AND accepted_at_utc GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9] [0-9][0-9]:[0-9][0-9]:[0-9][0-9].[0-9][0-9][0-9][0-9][0-9][0-9]' AND accepted_at_utc BETWEEN '2000-01-01 00:00:00.000000' AND '2100-12-31 23:59:59.999999' AND strftime('%Y-%m-%d %H:%M:%S',substr(accepted_at_utc,1,19),'+0 seconds') IS substr(accepted_at_utc,1,19)),
 command_id TEXT NOT NULL CHECK(typeof(command_id)='text' AND length(CAST(command_id AS BLOB))=36 AND instr(command_id,char(0))=0 AND command_id=lower(command_id) AND substr(command_id,9,1)='-' AND substr(command_id,14,1)='-' AND substr(command_id,19,1)='-' AND substr(command_id,24,1)='-' AND length(replace(command_id,'-',''))=32 AND replace(command_id,'-','') NOT GLOB '*[^0-9a-f]*'),
 PRIMARY KEY(quote_id),
 UNIQUE(quote_id,client_user_id),
 FOREIGN KEY(quote_id,client_user_id) REFERENCES b231_quotes(id,client_user_id) DEFERRABLE INITIALLY DEFERRED,
 FOREIGN KEY(command_id) REFERENCES b231_commands(id) DEFERRABLE INITIALLY DEFERRED
) STRICT, WITHOUT ROWID;

CREATE TRIGGER b231_acceptances_no_replace BEFORE INSERT ON b231_acceptances WHEN EXISTS(SELECT 1 FROM b231_acceptances WHERE quote_id=NEW.quote_id) BEGIN SELECT RAISE(ABORT,'identity collision'); END;

CREATE TRIGGER b231_acceptances_immutable BEFORE UPDATE ON b231_acceptances BEGIN SELECT RAISE(ABORT,'append only'); END;

CREATE TRIGGER b231_acceptances_no_delete BEFORE DELETE ON b231_acceptances BEGIN SELECT RAISE(ABORT,'append only'); END;

CREATE TRIGGER b231_acceptances_unsealed BEFORE INSERT ON b231_acceptances WHEN EXISTS(SELECT 1 FROM b231_commands WHERE id=NEW.command_id) BEGIN SELECT RAISE(ABORT,'command sealed'); END;

CREATE TRIGGER b231_consent_time BEFORE INSERT ON b231_acceptances WHEN NOT EXISTS(SELECT 1 FROM b231_quotes WHERE id=NEW.quote_id AND NEW.accepted_at_utc>=issued_at_utc AND NEW.accepted_at_utc<expires_at_utc) BEGIN SELECT RAISE(ABORT,'consent time'); END;

CREATE TABLE b231_proposals(
 id INTEGER NOT NULL CHECK(id>0),
 snapshot_version INTEGER NOT NULL CHECK(snapshot_version>0),
 inquiry_id INTEGER NOT NULL CHECK(inquiry_id>0),
 service_id INTEGER NOT NULL CHECK(service_id>0),
 provider_id INTEGER NOT NULL CHECK(provider_id>0),
 provider_user_id INTEGER NOT NULL CHECK(provider_user_id>0),
 client_id INTEGER NOT NULL CHECK(client_id>0),
 starts_at_us INTEGER NOT NULL CHECK(starts_at_us BETWEEN 946684800000000 AND 4133980799999999),
 ends_at_us INTEGER NOT NULL CHECK(ends_at_us BETWEEN 946684800000000 AND 4133980799999999),
 command_id TEXT NOT NULL CHECK(typeof(command_id)='text' AND length(CAST(command_id AS BLOB))=36 AND instr(command_id,char(0))=0 AND command_id=lower(command_id) AND substr(command_id,9,1)='-' AND substr(command_id,14,1)='-' AND substr(command_id,19,1)='-' AND substr(command_id,24,1)='-' AND length(replace(command_id,'-',''))=32 AND replace(command_id,'-','') NOT GLOB '*[^0-9a-f]*'),
 CHECK(ends_at_us=starts_at_us+3600000000 AND client_id<>provider_user_id),
 PRIMARY KEY(id),
 UNIQUE(id,inquiry_id,service_id,provider_id,provider_user_id,client_id,starts_at_us,ends_at_us),
 FOREIGN KEY(inquiry_id,service_id,provider_id,client_id) REFERENCES b231_inquiries(id,service_id,provider_id,client_id) DEFERRABLE INITIALLY DEFERRED,
 FOREIGN KEY(provider_id,provider_user_id) REFERENCES b231_providers(id,user_id) DEFERRABLE INITIALLY DEFERRED,
 FOREIGN KEY(command_id) REFERENCES b231_commands(id) DEFERRABLE INITIALLY DEFERRED
) STRICT, WITHOUT ROWID;

CREATE TRIGGER b231_proposals_no_replace BEFORE INSERT ON b231_proposals WHEN EXISTS(SELECT 1 FROM b231_proposals WHERE id=NEW.id) BEGIN SELECT RAISE(ABORT,'identity collision'); END;

CREATE TRIGGER b231_proposals_immutable BEFORE UPDATE ON b231_proposals BEGIN SELECT RAISE(ABORT,'append only'); END;

CREATE TRIGGER b231_proposals_no_delete BEFORE DELETE ON b231_proposals BEGIN SELECT RAISE(ABORT,'append only'); END;

CREATE TRIGGER b231_proposals_unsealed BEFORE INSERT ON b231_proposals WHEN EXISTS(SELECT 1 FROM b231_commands WHERE id=NEW.command_id) BEGIN SELECT RAISE(ABORT,'command sealed'); END;

CREATE TABLE b231_bindings(
 id TEXT NOT NULL CHECK(typeof(id)='text' AND length(CAST(id AS BLOB))=36 AND instr(id,char(0))=0 AND id=lower(id) AND substr(id,9,1)='-' AND substr(id,14,1)='-' AND substr(id,19,1)='-' AND substr(id,24,1)='-' AND length(replace(id,'-',''))=32 AND replace(id,'-','') NOT GLOB '*[^0-9a-f]*'),
 proposal_id INTEGER NOT NULL CHECK(proposal_id>0),
 quote_id TEXT NOT NULL CHECK(typeof(quote_id)='text' AND length(CAST(quote_id AS BLOB))=36 AND instr(quote_id,char(0))=0 AND quote_id=lower(quote_id) AND substr(quote_id,9,1)='-' AND substr(quote_id,14,1)='-' AND substr(quote_id,19,1)='-' AND substr(quote_id,24,1)='-' AND length(replace(quote_id,'-',''))=32 AND replace(quote_id,'-','') NOT GLOB '*[^0-9a-f]*'),
 client_id INTEGER NOT NULL CHECK(client_id>0),
 command_id TEXT NOT NULL CHECK(typeof(command_id)='text' AND length(CAST(command_id AS BLOB))=36 AND instr(command_id,char(0))=0 AND command_id=lower(command_id) AND substr(command_id,9,1)='-' AND substr(command_id,14,1)='-' AND substr(command_id,19,1)='-' AND substr(command_id,24,1)='-' AND length(replace(command_id,'-',''))=32 AND replace(command_id,'-','') NOT GLOB '*[^0-9a-f]*'),
 PRIMARY KEY(id),
 UNIQUE(proposal_id),
 UNIQUE(quote_id),
 UNIQUE(id,proposal_id,quote_id),
 FOREIGN KEY(proposal_id) REFERENCES b231_proposals(id) DEFERRABLE INITIALLY DEFERRED,
 FOREIGN KEY(quote_id,client_id) REFERENCES b231_acceptances(quote_id,client_user_id) DEFERRABLE INITIALLY DEFERRED,
 FOREIGN KEY(command_id) REFERENCES b231_commands(id) DEFERRABLE INITIALLY DEFERRED
) STRICT, WITHOUT ROWID;

CREATE TRIGGER b231_bindings_no_replace BEFORE INSERT ON b231_bindings WHEN EXISTS(SELECT 1 FROM b231_bindings WHERE id=NEW.id) BEGIN SELECT RAISE(ABORT,'identity collision'); END;

CREATE TRIGGER b231_bindings_immutable BEFORE UPDATE ON b231_bindings BEGIN SELECT RAISE(ABORT,'append only'); END;

CREATE TRIGGER b231_bindings_no_delete BEFORE DELETE ON b231_bindings BEGIN SELECT RAISE(ABORT,'append only'); END;

CREATE TRIGGER b231_bindings_unsealed BEFORE INSERT ON b231_bindings WHEN EXISTS(SELECT 1 FROM b231_commands WHERE id=NEW.command_id) BEGIN SELECT RAISE(ABORT,'command sealed'); END;

CREATE TRIGGER b231_bindings_alternate BEFORE INSERT ON b231_bindings WHEN EXISTS(SELECT 1 FROM b231_bindings WHERE proposal_id=NEW.proposal_id OR quote_id=NEW.quote_id) BEGIN SELECT RAISE(ABORT,'alternate identity collision'); END;

CREATE TRIGGER b231_binding_context BEFORE INSERT ON b231_bindings WHEN NOT EXISTS(SELECT 1 FROM b231_proposals p JOIN b231_quotes q ON q.id=NEW.quote_id WHERE p.id=NEW.proposal_id AND p.client_id=NEW.client_id AND p.client_id=q.client_user_id AND p.inquiry_id=q.service_inquiry_id AND p.service_id=q.service_id AND p.provider_id=q.provider_profile_id) BEGIN SELECT RAISE(ABORT,'binding context'); END;

CREATE TABLE b231_legacy(
 proposal_id INTEGER NOT NULL CHECK(proposal_id>0),
 claim_id TEXT NOT NULL CHECK(typeof(claim_id)='text' AND length(CAST(claim_id AS BLOB))=36 AND instr(claim_id,char(0))=0 AND claim_id=lower(claim_id) AND substr(claim_id,9,1)='-' AND substr(claim_id,14,1)='-' AND substr(claim_id,19,1)='-' AND substr(claim_id,24,1)='-' AND length(replace(claim_id,'-',''))=32 AND replace(claim_id,'-','') NOT GLOB '*[^0-9a-f]*'),
 PRIMARY KEY(proposal_id),
 UNIQUE(claim_id),
 FOREIGN KEY(proposal_id) REFERENCES b231_proposals(id) DEFERRABLE INITIALLY DEFERRED
) STRICT, WITHOUT ROWID;

CREATE TRIGGER b231_legacy_no_replace BEFORE INSERT ON b231_legacy WHEN EXISTS(SELECT 1 FROM b231_legacy WHERE proposal_id=NEW.proposal_id) BEGIN SELECT RAISE(ABORT,'identity collision'); END;

CREATE TRIGGER b231_legacy_immutable BEFORE UPDATE ON b231_legacy BEGIN SELECT RAISE(ABORT,'append only'); END;

CREATE TRIGGER b231_legacy_no_delete BEFORE DELETE ON b231_legacy BEGIN SELECT RAISE(ABORT,'append only'); END;

CREATE TABLE b231_activations(
 id TEXT NOT NULL CHECK(typeof(id)='text' AND length(CAST(id AS BLOB))=36 AND instr(id,char(0))=0 AND id=lower(id) AND substr(id,9,1)='-' AND substr(id,14,1)='-' AND substr(id,19,1)='-' AND substr(id,24,1)='-' AND length(replace(id,'-',''))=32 AND replace(id,'-','') NOT GLOB '*[^0-9a-f]*'),
 binding_id TEXT NOT NULL CHECK(typeof(binding_id)='text' AND length(CAST(binding_id AS BLOB))=36 AND instr(binding_id,char(0))=0 AND binding_id=lower(binding_id) AND substr(binding_id,9,1)='-' AND substr(binding_id,14,1)='-' AND substr(binding_id,19,1)='-' AND substr(binding_id,24,1)='-' AND length(replace(binding_id,'-',''))=32 AND replace(binding_id,'-','') NOT GLOB '*[^0-9a-f]*'),
 hold_id TEXT NOT NULL CHECK(typeof(hold_id)='text' AND length(CAST(hold_id AS BLOB))=36 AND instr(hold_id,char(0))=0 AND hold_id=lower(hold_id) AND substr(hold_id,9,1)='-' AND substr(hold_id,14,1)='-' AND substr(hold_id,19,1)='-' AND substr(hold_id,24,1)='-' AND length(replace(hold_id,'-',''))=32 AND replace(hold_id,'-','') NOT GLOB '*[^0-9a-f]*'),
 claim_id TEXT NOT NULL CHECK(typeof(claim_id)='text' AND length(CAST(claim_id AS BLOB))=36 AND instr(claim_id,char(0))=0 AND claim_id=lower(claim_id) AND substr(claim_id,9,1)='-' AND substr(claim_id,14,1)='-' AND substr(claim_id,19,1)='-' AND substr(claim_id,24,1)='-' AND length(replace(claim_id,'-',''))=32 AND replace(claim_id,'-','') NOT GLOB '*[^0-9a-f]*'),
 at_us INTEGER NOT NULL CHECK(at_us BETWEEN 946684800000000 AND 4133980799999999),
 provider_user_id INTEGER NOT NULL CHECK(provider_user_id>0),
 command_id TEXT NOT NULL CHECK(typeof(command_id)='text' AND length(CAST(command_id AS BLOB))=36 AND instr(command_id,char(0))=0 AND command_id=lower(command_id) AND substr(command_id,9,1)='-' AND substr(command_id,14,1)='-' AND substr(command_id,19,1)='-' AND substr(command_id,24,1)='-' AND length(replace(command_id,'-',''))=32 AND replace(command_id,'-','') NOT GLOB '*[^0-9a-f]*'),
 PRIMARY KEY(id),
 UNIQUE(binding_id),
 UNIQUE(hold_id),
 UNIQUE(claim_id),
 UNIQUE(id,binding_id,hold_id,claim_id),
 FOREIGN KEY(binding_id) REFERENCES b231_bindings(id) DEFERRABLE INITIALLY DEFERRED,
 FOREIGN KEY(hold_id,id,claim_id,binding_id) REFERENCES b231_holds(hold_id,activation_id,claim_id,binding_id) DEFERRABLE INITIALLY DEFERRED,
 FOREIGN KEY(command_id) REFERENCES b231_commands(id) DEFERRABLE INITIALLY DEFERRED
) STRICT, WITHOUT ROWID;

CREATE TRIGGER b231_activations_no_replace BEFORE INSERT ON b231_activations WHEN EXISTS(SELECT 1 FROM b231_activations WHERE id=NEW.id) BEGIN SELECT RAISE(ABORT,'identity collision'); END;

CREATE TRIGGER b231_activations_immutable BEFORE UPDATE ON b231_activations BEGIN SELECT RAISE(ABORT,'append only'); END;

CREATE TRIGGER b231_activations_no_delete BEFORE DELETE ON b231_activations BEGIN SELECT RAISE(ABORT,'append only'); END;

CREATE TRIGGER b231_activations_unsealed BEFORE INSERT ON b231_activations WHEN EXISTS(SELECT 1 FROM b231_commands WHERE id=NEW.command_id) BEGIN SELECT RAISE(ABORT,'command sealed'); END;

CREATE TRIGGER b231_activations_alternate BEFORE INSERT ON b231_activations WHEN EXISTS(SELECT 1 FROM b231_activations WHERE binding_id=NEW.binding_id OR hold_id=NEW.hold_id OR claim_id=NEW.claim_id) BEGIN SELECT RAISE(ABORT,'alternate identity collision'); END;

CREATE TRIGGER b231_activation_guard BEFORE INSERT ON b231_activations WHEN NOT EXISTS(SELECT 1 FROM b231_bindings b JOIN b231_proposals p ON p.id=b.proposal_id JOIN b231_quotes q ON q.id=b.quote_id WHERE b.id=NEW.binding_id AND p.provider_user_id=NEW.provider_user_id AND NEW.at_us<(CAST(strftime('%s',substr(q.expires_at_utc,1,19)) AS INTEGER)*1000000+CAST(substr(q.expires_at_utc,21,6) AS INTEGER))) BEGIN SELECT RAISE(ABORT,'activation context/time'); END;

CREATE TABLE b231_holds(
 hold_id TEXT NOT NULL CHECK(typeof(hold_id)='text' AND length(CAST(hold_id AS BLOB))=36 AND instr(hold_id,char(0))=0 AND hold_id=lower(hold_id) AND substr(hold_id,9,1)='-' AND substr(hold_id,14,1)='-' AND substr(hold_id,19,1)='-' AND substr(hold_id,24,1)='-' AND length(replace(hold_id,'-',''))=32 AND replace(hold_id,'-','') NOT GLOB '*[^0-9a-f]*'),
 reservation_id INTEGER NOT NULL CHECK(reservation_id>0),
 proposal_version INTEGER NOT NULL CHECK(proposal_version>0),
 quote_ref TEXT NOT NULL CHECK(typeof(quote_ref)='text' AND length(CAST(quote_ref AS BLOB))=36 AND instr(quote_ref,char(0))=0 AND quote_ref=lower(quote_ref) AND substr(quote_ref,9,1)='-' AND substr(quote_ref,14,1)='-' AND substr(quote_ref,19,1)='-' AND substr(quote_ref,24,1)='-' AND length(replace(quote_ref,'-',''))=32 AND replace(quote_ref,'-','') NOT GLOB '*[^0-9a-f]*'),
 inquiry_id INTEGER NOT NULL CHECK(inquiry_id>0),
 service_id INTEGER NOT NULL CHECK(service_id>0),
 provider_profile_id INTEGER NOT NULL CHECK(provider_profile_id>0),
 provider_user_id INTEGER NOT NULL CHECK(provider_user_id>0),
 client_user_id INTEGER NOT NULL CHECK(client_user_id>0),
 starts_at_us INTEGER NOT NULL CHECK(starts_at_us BETWEEN 946684800000000 AND 4133980799999999),
 ends_at_us INTEGER NOT NULL CHECK(ends_at_us BETWEEN 946684800000000 AND 4133980799999999),
 state TEXT NOT NULL CHECK(state IN ('held','committed','released','expired')),
 version INTEGER NOT NULL CHECK(version>0),
 accepted_at_us INTEGER NOT NULL CHECK(accepted_at_us BETWEEN 946684800000000 AND 4133980799999999),
 expires_at_us INTEGER NOT NULL CHECK(expires_at_us BETWEEN 946684800000000 AND 4133980799999999),
 updated_at_us INTEGER NOT NULL CHECK(updated_at_us BETWEEN 946684800000000 AND 4133980799999999),
 actor_kind TEXT NOT NULL CHECK(actor_kind IN ('provider','client','system')),
 actor_user_id INTEGER,
 binding_id TEXT NOT NULL CHECK(typeof(binding_id)='text' AND length(CAST(binding_id AS BLOB))=36 AND instr(binding_id,char(0))=0 AND binding_id=lower(binding_id) AND substr(binding_id,9,1)='-' AND substr(binding_id,14,1)='-' AND substr(binding_id,19,1)='-' AND substr(binding_id,24,1)='-' AND length(replace(binding_id,'-',''))=32 AND replace(binding_id,'-','') NOT GLOB '*[^0-9a-f]*'),
 activation_id TEXT NOT NULL CHECK(typeof(activation_id)='text' AND length(CAST(activation_id AS BLOB))=36 AND instr(activation_id,char(0))=0 AND activation_id=lower(activation_id) AND substr(activation_id,9,1)='-' AND substr(activation_id,14,1)='-' AND substr(activation_id,19,1)='-' AND substr(activation_id,24,1)='-' AND length(replace(activation_id,'-',''))=32 AND replace(activation_id,'-','') NOT GLOB '*[^0-9a-f]*'),
 claim_id TEXT NOT NULL CHECK(typeof(claim_id)='text' AND length(CAST(claim_id AS BLOB))=36 AND instr(claim_id,char(0))=0 AND claim_id=lower(claim_id) AND substr(claim_id,9,1)='-' AND substr(claim_id,14,1)='-' AND substr(claim_id,19,1)='-' AND substr(claim_id,24,1)='-' AND length(replace(claim_id,'-',''))=32 AND replace(claim_id,'-','') NOT GLOB '*[^0-9a-f]*'),
 command_id TEXT NOT NULL CHECK(typeof(command_id)='text' AND length(CAST(command_id AS BLOB))=36 AND instr(command_id,char(0))=0 AND command_id=lower(command_id) AND substr(command_id,9,1)='-' AND substr(command_id,14,1)='-' AND substr(command_id,19,1)='-' AND substr(command_id,24,1)='-' AND length(replace(command_id,'-',''))=32 AND replace(command_id,'-','') NOT GLOB '*[^0-9a-f]*'),
 CHECK(ends_at_us=starts_at_us+3600000000 AND expires_at_us=accepted_at_us+900000000),
 CHECK((state='held' AND version=1 AND updated_at_us=accepted_at_us) OR (state IN ('committed','released') AND version=2 AND updated_at_us>=accepted_at_us AND updated_at_us<expires_at_us) OR (state='expired' AND version=2 AND updated_at_us>=expires_at_us)),
 CHECK((state='held' AND actor_kind='provider' AND actor_user_id=provider_user_id) OR (state IN ('committed','expired') AND actor_kind='system' AND actor_user_id IS NULL) OR (state='released' AND ((actor_kind='provider' AND actor_user_id=provider_user_id) OR (actor_kind='client' AND actor_user_id=client_user_id)))),
 PRIMARY KEY(hold_id),
 UNIQUE(reservation_id),
 UNIQUE(quote_ref),
 UNIQUE(hold_id,binding_id,state),
 UNIQUE(hold_id,activation_id,claim_id,binding_id),
 FOREIGN KEY(reservation_id,inquiry_id,service_id,provider_profile_id,provider_user_id,client_user_id,starts_at_us,ends_at_us) REFERENCES b231_proposals(id,inquiry_id,service_id,provider_id,provider_user_id,client_id,starts_at_us,ends_at_us) DEFERRABLE INITIALLY DEFERRED,
 FOREIGN KEY(binding_id,reservation_id,quote_ref) REFERENCES b231_bindings(id,proposal_id,quote_id) DEFERRABLE INITIALLY DEFERRED,
 FOREIGN KEY(activation_id,binding_id,hold_id,claim_id) REFERENCES b231_activations(id,binding_id,hold_id,claim_id) DEFERRABLE INITIALLY DEFERRED,
 FOREIGN KEY(claim_id,hold_id,binding_id) REFERENCES b231_claims(id,hold_id,binding_id) DEFERRABLE INITIALLY DEFERRED,
 FOREIGN KEY(hold_id,version,state) REFERENCES b231_hold_events(aggregate_id,version,to_state) DEFERRABLE INITIALLY DEFERRED,
 FOREIGN KEY(command_id) REFERENCES b231_commands(id) DEFERRABLE INITIALLY DEFERRED
) STRICT, WITHOUT ROWID;

CREATE TRIGGER b231_holds_no_replace BEFORE INSERT ON b231_holds WHEN EXISTS(SELECT 1 FROM b231_holds WHERE hold_id=NEW.hold_id) BEGIN SELECT RAISE(ABORT,'identity collision'); END;

CREATE TRIGGER b231_holds_no_delete BEFORE DELETE ON b231_holds BEGIN SELECT RAISE(ABORT,'append only'); END;

CREATE TRIGGER b231_holds_unsealed BEFORE INSERT ON b231_holds WHEN EXISTS(SELECT 1 FROM b231_commands WHERE id=NEW.command_id) BEGIN SELECT RAISE(ABORT,'command sealed'); END;

CREATE TRIGGER b231_holds_unsealed_update BEFORE UPDATE ON b231_holds WHEN NEW.command_id=OLD.command_id OR EXISTS(SELECT 1 FROM b231_commands WHERE id=NEW.command_id) BEGIN SELECT RAISE(ABORT,'command sealed'); END;

CREATE TRIGGER b231_holds_alternate BEFORE INSERT ON b231_holds WHEN EXISTS(SELECT 1 FROM b231_holds WHERE reservation_id=NEW.reservation_id OR quote_ref=NEW.quote_ref) BEGIN SELECT RAISE(ABORT,'alternate identity collision'); END;

CREATE TRIGGER b231_hold_transition BEFORE UPDATE ON b231_holds WHEN OLD.state<>'held' OR NEW.state='held' OR NEW.version<>OLD.version+1 OR NEW.updated_at_us<OLD.updated_at_us OR NEW.hold_id IS NOT OLD.hold_id OR NEW.reservation_id IS NOT OLD.reservation_id OR NEW.proposal_version IS NOT OLD.proposal_version OR NEW.quote_ref IS NOT OLD.quote_ref OR NEW.inquiry_id IS NOT OLD.inquiry_id OR NEW.service_id IS NOT OLD.service_id OR NEW.provider_profile_id IS NOT OLD.provider_profile_id OR NEW.provider_user_id IS NOT OLD.provider_user_id OR NEW.client_user_id IS NOT OLD.client_user_id OR NEW.starts_at_us IS NOT OLD.starts_at_us OR NEW.ends_at_us IS NOT OLD.ends_at_us OR NEW.accepted_at_us IS NOT OLD.accepted_at_us OR NEW.expires_at_us IS NOT OLD.expires_at_us OR NEW.binding_id IS NOT OLD.binding_id OR NEW.activation_id IS NOT OLD.activation_id OR NEW.claim_id IS NOT OLD.claim_id BEGIN SELECT RAISE(ABORT,'hold transition'); END;

CREATE TABLE b231_claims(
 id TEXT NOT NULL CHECK(typeof(id)='text' AND length(CAST(id AS BLOB))=36 AND instr(id,char(0))=0 AND id=lower(id) AND substr(id,9,1)='-' AND substr(id,14,1)='-' AND substr(id,19,1)='-' AND substr(id,24,1)='-' AND length(replace(id,'-',''))=32 AND replace(id,'-','') NOT GLOB '*[^0-9a-f]*'),
 proposal_id INTEGER NOT NULL CHECK(proposal_id>0),
 inquiry_id INTEGER NOT NULL CHECK(inquiry_id>0),
 service_id INTEGER NOT NULL CHECK(service_id>0),
 provider_id INTEGER NOT NULL CHECK(provider_id>0),
 provider_user_id INTEGER NOT NULL CHECK(provider_user_id>0),
 client_id INTEGER NOT NULL CHECK(client_id>0),
 starts_at_us INTEGER NOT NULL CHECK(starts_at_us BETWEEN 946684800000000 AND 4133980799999999),
 ends_at_us INTEGER NOT NULL CHECK(ends_at_us BETWEEN 946684800000000 AND 4133980799999999),
 origin TEXT NOT NULL CHECK(origin IN ('payment','legacy')),
 hold_id TEXT,
 binding_id TEXT,
 phase TEXT NOT NULL CHECK(phase IN ('held','reserved','closed')),
 expected_hold TEXT,
 reason TEXT NOT NULL CHECK(reason IN ('accepted','committed','expired','released','cancelled','completed')),
 version INTEGER NOT NULL CHECK(version>0),
 at_us INTEGER NOT NULL CHECK(at_us BETWEEN 946684800000000 AND 4133980799999999),
 command_id TEXT NOT NULL CHECK(typeof(command_id)='text' AND length(CAST(command_id AS BLOB))=36 AND instr(command_id,char(0))=0 AND command_id=lower(command_id) AND substr(command_id,9,1)='-' AND substr(command_id,14,1)='-' AND substr(command_id,19,1)='-' AND substr(command_id,24,1)='-' AND length(replace(command_id,'-',''))=32 AND replace(command_id,'-','') NOT GLOB '*[^0-9a-f]*'),
 CHECK(ends_at_us=starts_at_us+3600000000 AND client_id<>provider_user_id),
 CHECK((origin='payment' AND hold_id IS NOT NULL AND binding_id IS NOT NULL AND expected_hold IS NOT NULL) OR (origin='legacy' AND hold_id IS NULL AND binding_id IS NULL AND expected_hold IS NULL AND phase IN ('reserved','closed'))),
 CHECK((phase='held' AND reason='accepted' AND expected_hold='held') OR (phase='reserved' AND reason='committed' AND (expected_hold='committed' OR origin='legacy')) OR (phase='closed' AND ((reason='expired' AND expected_hold='expired') OR (reason='released' AND expected_hold='released') OR (reason IN ('cancelled','completed') AND (expected_hold='committed' OR origin='legacy'))))),
 PRIMARY KEY(id),
 UNIQUE(proposal_id),
 UNIQUE(id,proposal_id,phase),
 UNIQUE(id,hold_id,binding_id),
 FOREIGN KEY(proposal_id,inquiry_id,service_id,provider_id,provider_user_id,client_id,starts_at_us,ends_at_us) REFERENCES b231_proposals(id,inquiry_id,service_id,provider_id,provider_user_id,client_id,starts_at_us,ends_at_us) DEFERRABLE INITIALLY DEFERRED,
 FOREIGN KEY(id,proposal_id,phase) REFERENCES b231_heads(claim_id,id,claim_phase) DEFERRABLE INITIALLY DEFERRED,
 FOREIGN KEY(hold_id,binding_id,expected_hold) REFERENCES b231_holds(hold_id,binding_id,state) DEFERRABLE INITIALLY DEFERRED,
 FOREIGN KEY(id,version,phase) REFERENCES b231_claim_events(aggregate_id,version,to_state) DEFERRABLE INITIALLY DEFERRED,
 FOREIGN KEY(command_id) REFERENCES b231_commands(id) DEFERRABLE INITIALLY DEFERRED
) STRICT, WITHOUT ROWID;

CREATE TRIGGER b231_claims_no_replace BEFORE INSERT ON b231_claims WHEN EXISTS(SELECT 1 FROM b231_claims WHERE id=NEW.id) BEGIN SELECT RAISE(ABORT,'identity collision'); END;

CREATE TRIGGER b231_claims_no_delete BEFORE DELETE ON b231_claims BEGIN SELECT RAISE(ABORT,'append only'); END;

CREATE TRIGGER b231_claims_unsealed BEFORE INSERT ON b231_claims WHEN EXISTS(SELECT 1 FROM b231_commands WHERE id=NEW.command_id) BEGIN SELECT RAISE(ABORT,'command sealed'); END;

CREATE TRIGGER b231_claims_unsealed_update BEFORE UPDATE ON b231_claims WHEN NEW.command_id=OLD.command_id OR EXISTS(SELECT 1 FROM b231_commands WHERE id=NEW.command_id) BEGIN SELECT RAISE(ABORT,'command sealed'); END;

CREATE TRIGGER b231_claims_alternate BEFORE INSERT ON b231_claims WHEN EXISTS(SELECT 1 FROM b231_claims WHERE proposal_id=NEW.proposal_id) BEGIN SELECT RAISE(ABORT,'alternate identity collision'); END;

CREATE TRIGGER b231_claim_origin BEFORE INSERT ON b231_claims WHEN (NEW.origin='legacy' AND NOT EXISTS(SELECT 1 FROM b231_legacy WHERE proposal_id=NEW.proposal_id AND claim_id=NEW.id)) OR (NEW.origin='payment' AND NOT EXISTS(SELECT 1 FROM b231_bindings WHERE id=NEW.binding_id AND proposal_id=NEW.proposal_id)) OR NEW.version<>1 BEGIN SELECT RAISE(ABORT,'claim origin'); END;

CREATE TRIGGER b231_claim_transition BEFORE UPDATE ON b231_claims WHEN OLD.phase='closed' OR NEW.version<>OLD.version+1 OR NEW.at_us<OLD.at_us OR NOT ((OLD.phase='held' AND NEW.phase IN ('reserved','closed')) OR (OLD.phase='reserved' AND NEW.phase='closed')) OR NEW.id IS NOT OLD.id OR NEW.proposal_id IS NOT OLD.proposal_id OR NEW.inquiry_id IS NOT OLD.inquiry_id OR NEW.service_id IS NOT OLD.service_id OR NEW.provider_id IS NOT OLD.provider_id OR NEW.provider_user_id IS NOT OLD.provider_user_id OR NEW.client_id IS NOT OLD.client_id OR NEW.starts_at_us IS NOT OLD.starts_at_us OR NEW.ends_at_us IS NOT OLD.ends_at_us OR NEW.origin IS NOT OLD.origin OR NEW.hold_id IS NOT OLD.hold_id OR NEW.binding_id IS NOT OLD.binding_id BEGIN SELECT RAISE(ABORT,'claim transition'); END;

CREATE TRIGGER b231_overlap_insert BEFORE INSERT ON b231_claims WHEN NEW.phase IN ('held','reserved') AND EXISTS(SELECT 1 FROM b231_claims WHERE id<>NEW.id AND provider_id=NEW.provider_id AND phase IN ('held','reserved') AND starts_at_us<NEW.ends_at_us AND ends_at_us>NEW.starts_at_us) BEGIN SELECT RAISE(ABORT,'occupancy overlap'); END;

CREATE TRIGGER b231_overlap_update BEFORE UPDATE ON b231_claims WHEN NEW.phase IN ('held','reserved') AND EXISTS(SELECT 1 FROM b231_claims WHERE id<>NEW.id AND provider_id=NEW.provider_id AND phase IN ('held','reserved') AND starts_at_us<NEW.ends_at_us AND ends_at_us>NEW.starts_at_us) BEGIN SELECT RAISE(ABORT,'occupancy overlap'); END;

CREATE TABLE b231_heads(
 id INTEGER NOT NULL CHECK(id>0),
 state TEXT NOT NULL CHECK(state IN ('requested','awaiting_payment','confirmed','expired','cancelled','completed','rejected')),
 version INTEGER NOT NULL CHECK(version>0),
 claim_id TEXT,
 claim_phase TEXT,
 at_us INTEGER NOT NULL CHECK(at_us BETWEEN 946684800000000 AND 4133980799999999),
 command_id TEXT NOT NULL CHECK(typeof(command_id)='text' AND length(CAST(command_id AS BLOB))=36 AND instr(command_id,char(0))=0 AND command_id=lower(command_id) AND substr(command_id,9,1)='-' AND substr(command_id,14,1)='-' AND substr(command_id,19,1)='-' AND substr(command_id,24,1)='-' AND length(replace(command_id,'-',''))=32 AND replace(command_id,'-','') NOT GLOB '*[^0-9a-f]*'),
 CHECK((claim_id IS NULL AND claim_phase IS NULL AND state IN ('requested','expired','cancelled','rejected')) OR (claim_id IS NOT NULL AND claim_phase IS NOT NULL AND ((state='awaiting_payment' AND claim_phase='held') OR (state='confirmed' AND claim_phase='reserved') OR (state IN ('expired','cancelled','completed') AND claim_phase='closed')))),
 PRIMARY KEY(id),
 UNIQUE(claim_id,id,claim_phase),
 FOREIGN KEY(id) REFERENCES b231_proposals(id) DEFERRABLE INITIALLY DEFERRED,
 FOREIGN KEY(claim_id,id,claim_phase) REFERENCES b231_claims(id,proposal_id,phase) DEFERRABLE INITIALLY DEFERRED,
 FOREIGN KEY(id,version,state) REFERENCES b231_proposal_events(aggregate_id,version,to_state) DEFERRABLE INITIALLY DEFERRED,
 FOREIGN KEY(command_id) REFERENCES b231_commands(id) DEFERRABLE INITIALLY DEFERRED
) STRICT, WITHOUT ROWID;

CREATE TRIGGER b231_heads_no_replace BEFORE INSERT ON b231_heads WHEN EXISTS(SELECT 1 FROM b231_heads WHERE id=NEW.id) BEGIN SELECT RAISE(ABORT,'identity collision'); END;

CREATE TRIGGER b231_heads_no_delete BEFORE DELETE ON b231_heads BEGIN SELECT RAISE(ABORT,'append only'); END;

CREATE TRIGGER b231_heads_unsealed BEFORE INSERT ON b231_heads WHEN EXISTS(SELECT 1 FROM b231_commands WHERE id=NEW.command_id) BEGIN SELECT RAISE(ABORT,'command sealed'); END;

CREATE TRIGGER b231_heads_unsealed_update BEFORE UPDATE ON b231_heads WHEN NEW.command_id=OLD.command_id OR EXISTS(SELECT 1 FROM b231_commands WHERE id=NEW.command_id) BEGIN SELECT RAISE(ABORT,'command sealed'); END;

CREATE TRIGGER b231_head_initial BEFORE INSERT ON b231_heads WHEN NEW.version<>1 OR (NEW.state<>'requested' AND NOT (NEW.state='confirmed' AND EXISTS(SELECT 1 FROM b231_legacy WHERE proposal_id=NEW.id AND claim_id=NEW.claim_id))) BEGIN SELECT RAISE(ABORT,'initial head'); END;

CREATE TRIGGER b231_head_transition BEFORE UPDATE ON b231_heads WHEN NEW.id<>OLD.id OR NEW.version<>OLD.version+1 OR NEW.at_us<OLD.at_us OR NOT ((OLD.state='requested' AND NEW.state IN ('awaiting_payment','expired','cancelled','rejected')) OR (OLD.state='awaiting_payment' AND NEW.state IN ('confirmed','expired','cancelled')) OR (OLD.state='confirmed' AND NEW.state IN ('cancelled','completed'))) OR (OLD.claim_id IS NOT NULL AND NEW.claim_id IS NOT OLD.claim_id) BEGIN SELECT RAISE(ABORT,'head transition'); END;

CREATE TABLE b231_hold_events(
 aggregate_id TEXT NOT NULL CHECK(typeof(aggregate_id)='text' AND length(CAST(aggregate_id AS BLOB))=36 AND instr(aggregate_id,char(0))=0 AND aggregate_id=lower(aggregate_id) AND substr(aggregate_id,9,1)='-' AND substr(aggregate_id,14,1)='-' AND substr(aggregate_id,19,1)='-' AND substr(aggregate_id,24,1)='-' AND length(replace(aggregate_id,'-',''))=32 AND replace(aggregate_id,'-','') NOT GLOB '*[^0-9a-f]*'),
 version INTEGER NOT NULL CHECK(version>0),
 from_state TEXT,
 to_state TEXT NOT NULL,
 at_us INTEGER NOT NULL CHECK(at_us BETWEEN 946684800000000 AND 4133980799999999),
 command_id TEXT NOT NULL CHECK(typeof(command_id)='text' AND length(CAST(command_id AS BLOB))=36 AND instr(command_id,char(0))=0 AND command_id=lower(command_id) AND substr(command_id,9,1)='-' AND substr(command_id,14,1)='-' AND substr(command_id,19,1)='-' AND substr(command_id,24,1)='-' AND length(replace(command_id,'-',''))=32 AND replace(command_id,'-','') NOT GLOB '*[^0-9a-f]*'),
 PRIMARY KEY(aggregate_id,version),
 UNIQUE(aggregate_id,version,to_state),
 FOREIGN KEY(aggregate_id) REFERENCES b231_holds(hold_id) DEFERRABLE INITIALLY DEFERRED,
 FOREIGN KEY(command_id) REFERENCES b231_commands(id) DEFERRABLE INITIALLY DEFERRED
) STRICT, WITHOUT ROWID;

CREATE TRIGGER b231_hold_events_immutable BEFORE UPDATE ON b231_hold_events BEGIN SELECT RAISE(ABORT,'append only'); END;

CREATE TRIGGER b231_hold_events_no_delete BEFORE DELETE ON b231_hold_events BEGIN SELECT RAISE(ABORT,'append only'); END;

CREATE TRIGGER b231_hold_events_unsealed BEFORE INSERT ON b231_hold_events WHEN EXISTS(SELECT 1 FROM b231_commands WHERE id=NEW.command_id) BEGIN SELECT RAISE(ABORT,'command sealed'); END;

CREATE TRIGGER b231_hold_events_collision BEFORE INSERT ON b231_hold_events WHEN EXISTS(SELECT 1 FROM b231_hold_events WHERE aggregate_id=NEW.aggregate_id AND version=NEW.version) OR NOT EXISTS(SELECT 1 FROM b231_holds WHERE hold_id=NEW.aggregate_id AND version=NEW.version AND state=NEW.to_state AND command_id=NEW.command_id) OR (NEW.version=1 AND NEW.from_state IS NOT NULL) OR (NEW.version>1 AND NOT EXISTS(SELECT 1 FROM b231_hold_events WHERE aggregate_id=NEW.aggregate_id AND version=NEW.version-1 AND to_state=NEW.from_state)) BEGIN SELECT RAISE(ABORT,'event chain'); END;

CREATE TRIGGER b231_holds_event_insert AFTER INSERT ON b231_holds BEGIN INSERT INTO b231_hold_events VALUES(NEW.hold_id,NEW.version,NULL,NEW.state,NEW.updated_at_us,NEW.command_id); END;

CREATE TRIGGER b231_holds_event_update AFTER UPDATE ON b231_holds BEGIN INSERT INTO b231_hold_events VALUES(NEW.hold_id,NEW.version,OLD.state,NEW.state,NEW.updated_at_us,NEW.command_id); END;

CREATE TABLE b231_claim_events(
 aggregate_id TEXT NOT NULL CHECK(typeof(aggregate_id)='text' AND length(CAST(aggregate_id AS BLOB))=36 AND instr(aggregate_id,char(0))=0 AND aggregate_id=lower(aggregate_id) AND substr(aggregate_id,9,1)='-' AND substr(aggregate_id,14,1)='-' AND substr(aggregate_id,19,1)='-' AND substr(aggregate_id,24,1)='-' AND length(replace(aggregate_id,'-',''))=32 AND replace(aggregate_id,'-','') NOT GLOB '*[^0-9a-f]*'),
 version INTEGER NOT NULL CHECK(version>0),
 from_state TEXT,
 to_state TEXT NOT NULL,
 at_us INTEGER NOT NULL CHECK(at_us BETWEEN 946684800000000 AND 4133980799999999),
 command_id TEXT NOT NULL CHECK(typeof(command_id)='text' AND length(CAST(command_id AS BLOB))=36 AND instr(command_id,char(0))=0 AND command_id=lower(command_id) AND substr(command_id,9,1)='-' AND substr(command_id,14,1)='-' AND substr(command_id,19,1)='-' AND substr(command_id,24,1)='-' AND length(replace(command_id,'-',''))=32 AND replace(command_id,'-','') NOT GLOB '*[^0-9a-f]*'),
 PRIMARY KEY(aggregate_id,version),
 UNIQUE(aggregate_id,version,to_state),
 FOREIGN KEY(aggregate_id) REFERENCES b231_claims(id) DEFERRABLE INITIALLY DEFERRED,
 FOREIGN KEY(command_id) REFERENCES b231_commands(id) DEFERRABLE INITIALLY DEFERRED
) STRICT, WITHOUT ROWID;

CREATE TRIGGER b231_claim_events_immutable BEFORE UPDATE ON b231_claim_events BEGIN SELECT RAISE(ABORT,'append only'); END;

CREATE TRIGGER b231_claim_events_no_delete BEFORE DELETE ON b231_claim_events BEGIN SELECT RAISE(ABORT,'append only'); END;

CREATE TRIGGER b231_claim_events_unsealed BEFORE INSERT ON b231_claim_events WHEN EXISTS(SELECT 1 FROM b231_commands WHERE id=NEW.command_id) BEGIN SELECT RAISE(ABORT,'command sealed'); END;

CREATE TRIGGER b231_claim_events_collision BEFORE INSERT ON b231_claim_events WHEN EXISTS(SELECT 1 FROM b231_claim_events WHERE aggregate_id=NEW.aggregate_id AND version=NEW.version) OR NOT EXISTS(SELECT 1 FROM b231_claims WHERE id=NEW.aggregate_id AND version=NEW.version AND phase=NEW.to_state AND command_id=NEW.command_id) OR (NEW.version=1 AND NEW.from_state IS NOT NULL) OR (NEW.version>1 AND NOT EXISTS(SELECT 1 FROM b231_claim_events WHERE aggregate_id=NEW.aggregate_id AND version=NEW.version-1 AND to_state=NEW.from_state)) BEGIN SELECT RAISE(ABORT,'event chain'); END;

CREATE TRIGGER b231_claims_event_insert AFTER INSERT ON b231_claims BEGIN INSERT INTO b231_claim_events VALUES(NEW.id,NEW.version,NULL,NEW.phase,NEW.at_us,NEW.command_id); END;

CREATE TRIGGER b231_claims_event_update AFTER UPDATE ON b231_claims BEGIN INSERT INTO b231_claim_events VALUES(NEW.id,NEW.version,OLD.phase,NEW.phase,NEW.at_us,NEW.command_id); END;

CREATE TABLE b231_proposal_events(
 aggregate_id INTEGER NOT NULL CHECK(aggregate_id>0),
 version INTEGER NOT NULL CHECK(version>0),
 from_state TEXT,
 to_state TEXT NOT NULL,
 at_us INTEGER NOT NULL CHECK(at_us BETWEEN 946684800000000 AND 4133980799999999),
 command_id TEXT NOT NULL CHECK(typeof(command_id)='text' AND length(CAST(command_id AS BLOB))=36 AND instr(command_id,char(0))=0 AND command_id=lower(command_id) AND substr(command_id,9,1)='-' AND substr(command_id,14,1)='-' AND substr(command_id,19,1)='-' AND substr(command_id,24,1)='-' AND length(replace(command_id,'-',''))=32 AND replace(command_id,'-','') NOT GLOB '*[^0-9a-f]*'),
 PRIMARY KEY(aggregate_id,version),
 UNIQUE(aggregate_id,version,to_state),
 FOREIGN KEY(aggregate_id) REFERENCES b231_heads(id) DEFERRABLE INITIALLY DEFERRED,
 FOREIGN KEY(command_id) REFERENCES b231_commands(id) DEFERRABLE INITIALLY DEFERRED
) STRICT, WITHOUT ROWID;

CREATE TRIGGER b231_proposal_events_immutable BEFORE UPDATE ON b231_proposal_events BEGIN SELECT RAISE(ABORT,'append only'); END;

CREATE TRIGGER b231_proposal_events_no_delete BEFORE DELETE ON b231_proposal_events BEGIN SELECT RAISE(ABORT,'append only'); END;

CREATE TRIGGER b231_proposal_events_unsealed BEFORE INSERT ON b231_proposal_events WHEN EXISTS(SELECT 1 FROM b231_commands WHERE id=NEW.command_id) BEGIN SELECT RAISE(ABORT,'command sealed'); END;

CREATE TRIGGER b231_proposal_events_collision BEFORE INSERT ON b231_proposal_events WHEN EXISTS(SELECT 1 FROM b231_proposal_events WHERE aggregate_id=NEW.aggregate_id AND version=NEW.version) OR NOT EXISTS(SELECT 1 FROM b231_heads WHERE id=NEW.aggregate_id AND version=NEW.version AND state=NEW.to_state AND command_id=NEW.command_id) OR (NEW.version=1 AND NEW.from_state IS NOT NULL) OR (NEW.version>1 AND NOT EXISTS(SELECT 1 FROM b231_proposal_events WHERE aggregate_id=NEW.aggregate_id AND version=NEW.version-1 AND to_state=NEW.from_state)) BEGIN SELECT RAISE(ABORT,'event chain'); END;

CREATE TRIGGER b231_heads_event_insert AFTER INSERT ON b231_heads BEGIN INSERT INTO b231_proposal_events VALUES(NEW.id,NEW.version,NULL,NEW.state,NEW.at_us,NEW.command_id); END;

CREATE TRIGGER b231_heads_event_update AFTER UPDATE ON b231_heads BEGIN INSERT INTO b231_proposal_events VALUES(NEW.id,NEW.version,OLD.state,NEW.state,NEW.at_us,NEW.command_id); END;

CREATE TABLE b231_clock(
 id INTEGER NOT NULL CHECK(id=1),
 at_us INTEGER NOT NULL CHECK(at_us BETWEEN 946684800000000 AND 4133980799999999),
 command_id TEXT NOT NULL CHECK(typeof(command_id)='text' AND length(CAST(command_id AS BLOB))=36 AND instr(command_id,char(0))=0 AND command_id=lower(command_id) AND substr(command_id,9,1)='-' AND substr(command_id,14,1)='-' AND substr(command_id,19,1)='-' AND substr(command_id,24,1)='-' AND length(replace(command_id,'-',''))=32 AND replace(command_id,'-','') NOT GLOB '*[^0-9a-f]*'),
 PRIMARY KEY(id),
 FOREIGN KEY(command_id) REFERENCES b231_commands(id) DEFERRABLE INITIALLY DEFERRED
) STRICT, WITHOUT ROWID;

CREATE TRIGGER b231_clock_no_replace BEFORE INSERT ON b231_clock WHEN EXISTS(SELECT 1 FROM b231_clock WHERE id=NEW.id) BEGIN SELECT RAISE(ABORT,'identity collision'); END;

CREATE TRIGGER b231_clock_no_delete BEFORE DELETE ON b231_clock BEGIN SELECT RAISE(ABORT,'append only'); END;

CREATE TRIGGER b231_clock_unsealed BEFORE INSERT ON b231_clock WHEN EXISTS(SELECT 1 FROM b231_commands WHERE id=NEW.command_id) BEGIN SELECT RAISE(ABORT,'command sealed'); END;

CREATE TRIGGER b231_clock_unsealed_update BEFORE UPDATE ON b231_clock WHEN NEW.command_id=OLD.command_id OR EXISTS(SELECT 1 FROM b231_commands WHERE id=NEW.command_id) BEGIN SELECT RAISE(ABORT,'command sealed'); END;

CREATE TRIGGER b231_clock_monotonic BEFORE UPDATE ON b231_clock WHEN NEW.at_us<OLD.at_us OR NEW.id<>OLD.id BEGIN SELECT RAISE(ABORT,'clock regression'); END;

CREATE TABLE b231_financial(
 id TEXT NOT NULL CHECK(typeof(id)='text' AND length(CAST(id AS BLOB))=36 AND instr(id,char(0))=0 AND id=lower(id) AND substr(id,9,1)='-' AND substr(id,14,1)='-' AND substr(id,19,1)='-' AND substr(id,24,1)='-' AND length(replace(id,'-',''))=32 AND replace(id,'-','') NOT GLOB '*[^0-9a-f]*'),
 binding_id TEXT NOT NULL CHECK(typeof(binding_id)='text' AND length(CAST(binding_id AS BLOB))=36 AND instr(binding_id,char(0))=0 AND binding_id=lower(binding_id) AND substr(binding_id,9,1)='-' AND substr(binding_id,14,1)='-' AND substr(binding_id,19,1)='-' AND substr(binding_id,24,1)='-' AND length(replace(binding_id,'-',''))=32 AND replace(binding_id,'-','') NOT GLOB '*[^0-9a-f]*'),
 hold_id TEXT NOT NULL CHECK(typeof(hold_id)='text' AND length(CAST(hold_id AS BLOB))=36 AND instr(hold_id,char(0))=0 AND hold_id=lower(hold_id) AND substr(hold_id,9,1)='-' AND substr(hold_id,14,1)='-' AND substr(hold_id,19,1)='-' AND substr(hold_id,24,1)='-' AND length(replace(hold_id,'-',''))=32 AND replace(hold_id,'-','') NOT GLOB '*[^0-9a-f]*'),
 amount_cents INTEGER NOT NULL CHECK(amount_cents BETWEEN 1 AND 1000000000),
 synthetic INTEGER NOT NULL CHECK(synthetic=1),
 disposition TEXT NOT NULL CHECK(disposition IN ('verified_fixture','late_reconcile')),
 at_us INTEGER NOT NULL CHECK(at_us BETWEEN 946684800000000 AND 4133980799999999),
 command_id TEXT NOT NULL CHECK(typeof(command_id)='text' AND length(CAST(command_id AS BLOB))=36 AND instr(command_id,char(0))=0 AND command_id=lower(command_id) AND substr(command_id,9,1)='-' AND substr(command_id,14,1)='-' AND substr(command_id,19,1)='-' AND substr(command_id,24,1)='-' AND length(replace(command_id,'-',''))=32 AND replace(command_id,'-','') NOT GLOB '*[^0-9a-f]*'),
 PRIMARY KEY(id),
 FOREIGN KEY(binding_id) REFERENCES b231_bindings(id) DEFERRABLE INITIALLY DEFERRED,
 FOREIGN KEY(hold_id,binding_id) REFERENCES b231_holds(hold_id,binding_id) DEFERRABLE INITIALLY DEFERRED,
 FOREIGN KEY(command_id) REFERENCES b231_commands(id) DEFERRABLE INITIALLY DEFERRED
) STRICT, WITHOUT ROWID;

CREATE TRIGGER b231_financial_no_replace BEFORE INSERT ON b231_financial WHEN EXISTS(SELECT 1 FROM b231_financial WHERE id=NEW.id) BEGIN SELECT RAISE(ABORT,'identity collision'); END;

CREATE TRIGGER b231_financial_immutable BEFORE UPDATE ON b231_financial BEGIN SELECT RAISE(ABORT,'append only'); END;

CREATE TRIGGER b231_financial_no_delete BEFORE DELETE ON b231_financial BEGIN SELECT RAISE(ABORT,'append only'); END;

CREATE TRIGGER b231_financial_unsealed BEFORE INSERT ON b231_financial WHEN EXISTS(SELECT 1 FROM b231_commands WHERE id=NEW.command_id) BEGIN SELECT RAISE(ABORT,'command sealed'); END;

CREATE UNIQUE INDEX b231_hold_binding_state ON b231_holds(hold_id,binding_id);

CREATE TRIGGER b231_financial_amount BEFORE INSERT ON b231_financial WHEN NOT EXISTS(SELECT 1 FROM b231_bindings b JOIN b231_quotes q ON q.id=b.quote_id JOIN b231_holds h ON h.hold_id=NEW.hold_id AND h.binding_id=b.id WHERE b.id=NEW.binding_id AND q.amount_cents=NEW.amount_cents) BEGIN SELECT RAISE(ABORT,'financial snapshot'); END;

CREATE INDEX b231_claim_provider_interval ON b231_claims(provider_id,phase,starts_at_us,ends_at_us);

CREATE INDEX b231_hold_due ON b231_holds(state,expires_at_us);

CREATE TRIGGER b231_command_seal BEFORE INSERT ON b231_commands WHEN json_type(NEW.result_json)<>'object' OR json_type(NEW.result_json,'$.code')<>'text' OR (NEW.outcome='success' AND ((NEW.action='publish' AND NOT EXISTS(SELECT 1 FROM b231_prices WHERE command_id=NEW.id)) OR (NEW.action='quote' AND NOT EXISTS(SELECT 1 FROM b231_quotes WHERE command_id=NEW.id)) OR (NEW.action='propose' AND NOT EXISTS(SELECT 1 FROM b231_bindings WHERE command_id=NEW.id)) OR (NEW.action='accept' AND NOT EXISTS(SELECT 1 FROM b231_activations WHERE command_id=NEW.id)))) BEGIN SELECT RAISE(ABORT,'incomplete command'); END;

-- Additional cross-layer invariants. No exemption for an active PHP callback.
CREATE UNIQUE INDEX b231_proposal_snapshot_identity ON b231_proposals(id,snapshot_version);
CREATE TRIGGER b231_hold_activation_insert BEFORE INSERT ON b231_holds
WHEN NOT EXISTS(SELECT 1 FROM b231_activations a JOIN b231_proposals p ON p.id=NEW.reservation_id WHERE a.id=NEW.activation_id AND a.at_us=NEW.accepted_at_us AND a.provider_user_id=NEW.provider_user_id AND p.snapshot_version=NEW.proposal_version)
BEGIN SELECT RAISE(ABORT,'hold activation snapshot'); END;
CREATE TRIGGER b231_legacy_after_creation BEFORE INSERT ON b231_legacy
WHEN EXISTS(SELECT 1 FROM b231_clock)
BEGIN SELECT RAISE(ABORT,'legacy manifest already sealed'); END;
CREATE TRIGGER b231_command_references BEFORE INSERT ON b231_commands
WHEN NEW.action NOT IN ('publish','quote','propose','accept','commit','release','close','resolve','import_fixture','probe','inspect')
OR (NEW.action IN ('publish','accept') AND NEW.actor_kind<>'provider')
OR (NEW.action IN ('quote','propose') AND NEW.actor_kind<>'client')
OR (NEW.action IN ('commit','resolve','import_fixture') AND NEW.actor_kind<>'system')
OR (NEW.outcome='rejected' AND EXISTS(SELECT 1 FROM b231_activations WHERE command_id=NEW.id))
OR (NEW.outcome='success' AND (
 (NEW.action='publish' AND NOT EXISTS(SELECT 1 FROM b231_prices p WHERE p.id=json_extract(NEW.result_json,'$.price_id') AND p.command_id=NEW.id AND p.created_by=NEW.actor_user_id))
 OR (NEW.action='quote' AND NOT EXISTS(SELECT 1 FROM b231_quotes q WHERE q.id=json_extract(NEW.result_json,'$.quote_id') AND q.command_id=NEW.id AND q.client_user_id=NEW.actor_user_id))
 OR (NEW.action='propose' AND NOT EXISTS(SELECT 1 FROM b231_bindings b WHERE b.id=json_extract(NEW.result_json,'$.binding_id') AND b.proposal_id=json_extract(NEW.result_json,'$.proposal_id') AND b.command_id=NEW.id AND b.client_id=NEW.actor_user_id))
 OR (NEW.action='accept' AND NOT EXISTS(SELECT 1 FROM b231_activations a JOIN b231_holds h ON h.hold_id=a.hold_id JOIN b231_claims c ON c.id=a.claim_id JOIN b231_heads p ON p.id=h.reservation_id WHERE a.command_id=NEW.id AND h.command_id=NEW.id AND c.command_id=NEW.id AND p.command_id=NEW.id AND a.hold_id=json_extract(NEW.result_json,'$.hold_id') AND a.claim_id=json_extract(NEW.result_json,'$.claim_id') AND a.provider_user_id=NEW.actor_user_id AND p.state='awaiting_payment'))
 OR (NEW.action IN ('commit','release') AND NOT EXISTS(SELECT 1 FROM b231_holds h WHERE h.hold_id=json_extract(NEW.result_json,'$.hold_id') AND h.command_id=NEW.id AND h.state=CASE NEW.action WHEN 'commit' THEN 'committed' ELSE 'released' END))
 OR (NEW.action='close' AND NOT EXISTS(SELECT 1 FROM b231_heads p WHERE p.id=json_extract(NEW.result_json,'$.proposal_id') AND p.command_id=NEW.id AND p.state IN ('cancelled','completed')))
))
BEGIN SELECT RAISE(ABORT,'receipt references or actor'); END;

CREATE TABLE b231_fixture_seal(id INTEGER NOT NULL PRIMARY KEY CHECK(id=1)) STRICT, WITHOUT ROWID;
CREATE TRIGGER b231_fixture_seal_immutable BEFORE UPDATE ON b231_fixture_seal BEGIN SELECT RAISE(ABORT,'sealed fixture'); END;
CREATE TRIGGER b231_fixture_seal_delete BEFORE DELETE ON b231_fixture_seal BEGIN SELECT RAISE(ABORT,'sealed fixture'); END;
CREATE TRIGGER b231_fixture_seal_replace BEFORE INSERT ON b231_fixture_seal WHEN EXISTS(SELECT 1 FROM b231_fixture_seal) BEGIN SELECT RAISE(ABORT,'sealed fixture'); END;
CREATE TRIGGER b231_legacy_sealed BEFORE INSERT ON b231_legacy WHEN EXISTS(SELECT 1 FROM b231_fixture_seal) BEGIN SELECT RAISE(ABORT,'sealed legacy manifest'); END;
CREATE TRIGGER b231_head_active_insert BEFORE INSERT ON b231_heads WHEN NEW.state IN ('requested','awaiting_payment','confirmed') AND EXISTS(SELECT 1 FROM b231_heads h JOIN b231_proposals p ON p.id=h.id JOIN b231_proposals n ON n.id=NEW.id WHERE h.id<>NEW.id AND p.inquiry_id=n.inquiry_id AND h.state IN ('requested','awaiting_payment','confirmed')) BEGIN SELECT RAISE(ABORT,'active inquiry'); END;
CREATE TRIGGER b231_head_active_update BEFORE UPDATE ON b231_heads WHEN NEW.state IN ('requested','awaiting_payment','confirmed') AND EXISTS(SELECT 1 FROM b231_heads h JOIN b231_proposals p ON p.id=h.id JOIN b231_proposals n ON n.id=NEW.id WHERE h.id<>NEW.id AND p.inquiry_id=n.inquiry_id AND h.state IN ('requested','awaiting_payment','confirmed')) BEGIN SELECT RAISE(ABORT,'active inquiry'); END;
