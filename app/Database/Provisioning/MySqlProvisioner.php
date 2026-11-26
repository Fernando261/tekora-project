<?php

namespace App\Database\Provisioning;

use App\Database\PricingMySqlConnection;
use Database\Seeders\ServiceCategorySeeder;
use Illuminate\Database\Migrations\Migrator;

final class MySqlProvisioner
{
    public const CONNECTION = 'tekora_provisioning';

    public const DEFINER = 'tekora_definer@localhost';

    public function run(PricingMySqlConnection $connection, Migrator $migrator, bool $verify, bool $upgrade): string
    {
        if ($verify && $upgrade) {
            throw new ProvisioningFailure('Usa --verify o --upgrade por separado.');
        }
        $session = $this->preflight($connection);
        // Keep the V8.1.8 lock name so a previous provisioner cannot run concurrently.
        $name = hash('sha256', 'tekora:provision:v818');
        $connection->setReconnector(static fn () => throw new ProvisioningFailure('Sesión de instalación perdida; revisar el esquema antes de reintentar.'));
        if ((int) $connection->selectOne('SELECT GET_LOCK(?, 0) AS acquired', [$name])->acquired !== 1) {
            throw new ProvisioningFailure('Otro proceso controla este esquema.');
        }

        try {
            if ($this->objects($connection) !== []) {
                $version = $this->verify($connection);
                if (! $upgrade || $version === MySqlMigrationPlan::CURRENT) {
                    $count = count(MySqlMigrationPlan::identities($version));

                    return "MySQL verificado: {$count}/{$count} migraciones; esquema e identidades coinciden.";
                }
                $this->maintenance($connection, $session);
                $this->mark($connection, 'ready', ['state' => 'upgrading']);
                $this->extend($connection, $migrator, 'upgrading', $version);

                return 'MySQL actualizado: 41/41 migraciones y esquema verificado.';
            }
            if ($verify || $upgrade) {
                throw new ProvisioningFailure('Esquema vacío. Este modo requiere una instalación sellada.');
            }
            if ((int) $session->trust_creators !== 1) {
                throw new ProvisioningFailure('El administrador debe habilitar temporalmente la creación de rutinas con binlog.');
            }
            $connection->unprepared('CREATE TABLE tekora_provisioning (
                id TINYINT PRIMARY KEY CHECK (id=1),
                state VARCHAR(16) NOT NULL,
                migration_sha256 CHAR(64) NOT NULL,
                schema_sha256 CHAR(64) NULL
            ) ENGINE=InnoDB');
            $connection->table('tekora_provisioning')->insert([
                'id' => 1, 'state' => 'installing', 'migration_sha256' => MySqlMigrationPlan::digest(MySqlMigrationPlan::CURRENT),
            ]);

            $connection->usingProvisioningDefiner(fn () => $migrator->usingConnection(self::CONNECTION, function () use ($migrator) {
                $migrator->getRepository()->createRepository();
                $migrator->run(MySqlMigrationPlan::files());
                app(ServiceCategorySeeder::class)->run();
            }));
            $this->extend($connection, $migrator, 'installing', 1);

            return 'MySQL instalado: 41/41 migraciones y esquema verificado.';
        } finally {
            // Disconnecting frees the session lock even when the last statement failed.
            $connection->disconnect();
        }
    }

    private function maintenance(PricingMySqlConnection $db, object $session): void
    {
        if ((int) $session->trust_creators !== 1
            || $db->selectOne("SELECT account_locked FROM mysql.user WHERE User='tekora_app' AND Host='localhost'")?->account_locked !== 'Y'
            || (int) $db->selectOne("SELECT COUNT(*) AS sessions FROM performance_schema.threads
                WHERE PROCESSLIST_ID <> CONNECTION_ID() AND (PROCESSLIST_USER IN ('tekora_app','tekora_migrator')
                    OR PROCESSLIST_DB='tekora')")->sessions !== 0) {
            throw new ProvisioningFailure('Actualización bloqueada. Detener escritores, bloquear la cuenta de aplicación y drenar las sesiones.');
        }
    }

    private function extend(PricingMySqlConnection $connection, Migrator $migrator, string $state, int $version): void
    {
        $connection->usingProvisioningDefiner(fn () => $migrator->usingConnection(self::CONNECTION, function () use ($migrator, $version) {
            if ($version === 1) {
                $migrator->run(MySqlMigrationPlan::extensions());
            }
            $migrator->run(MySqlMigrationPlan::trust());
        }));
        $this->verifyLedgerAndClock($connection, MySqlMigrationPlan::CURRENT);
        $this->mark($connection, $state, [
            'state' => 'ready', 'migration_sha256' => MySqlMigrationPlan::digest(MySqlMigrationPlan::CURRENT), 'schema_sha256' => $this->schemaDigest($connection),
        ]);
        $this->verify($connection);
    }

    private function mark(PricingMySqlConnection $db, string $previous, array $values): void
    {
        if ($db->table('tekora_provisioning')->where('id', 1)->where('state', $previous)->update($values) !== 1) {
            throw new ProvisioningFailure('No se confirmó la transición del sello de aprovisionamiento.');
        }
    }

    private function preflight(PricingMySqlConnection $db): object
    {
        $s = $db->selectOne('SELECT VERSION() AS version, CURRENT_USER() AS actor, DATABASE() AS db,
            @@default_storage_engine AS engine, @@session.character_set_connection AS charset,
            @@session.collation_connection AS collation, @@session.time_zone AS timezone,
            @@session.autocommit AS autocommit, @@session.foreign_key_checks AS fk, @@session.sql_mode AS modes,
            @@session.transaction_isolation AS isolation_mode, @@global.log_bin AS log_bin,
            @@session.sql_log_bin AS session_binlog, @@global.binlog_format AS binlog_format,
            @@global.log_bin_trust_function_creators AS trust_creators');
        $schema = $db->selectOne('SELECT DEFAULT_CHARACTER_SET_NAME AS charset, DEFAULT_COLLATION_NAME AS collation
            FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=DATABASE()');
        if (PHP_INT_SIZE !== 8 || ! preg_match('/^8\.4\.13(?:-commercial)?$/D', $s->version)
            || $s->actor !== 'tekora_migrator@localhost' || $s->db !== 'tekora' || $s->engine !== 'InnoDB'
            || $s->charset !== 'utf8mb4' || $s->collation !== 'utf8mb4_unicode_ci'
            || $schema?->charset !== 'utf8mb4' || $schema?->collation !== 'utf8mb4_unicode_ci'
            || $s->timezone !== '+00:00' || (int) $s->fk !== 1 || (int) $s->autocommit !== 1
            || ! in_array('STRICT_TRANS_TABLES', explode(',', $s->modes), true)
            || $s->isolation_mode !== 'REPEATABLE-READ' || (int) $s->log_bin !== 1 || (int) $s->session_binlog !== 1
            || $s->binlog_format !== 'ROW' || $db->getPdo()->inTransaction() || $db->transactionLevel() !== 0) {
            throw new ProvisioningFailure('MySQL incompatible: revisar identidad, versión, InnoDB, UTC, collation, FK, modo estricto, aislamiento y binlog ROW.');
        }
        $this->verifyClock($db, "TIMESTAMPDIFF(MICROSECOND, '1970-01-01 00:00:00', SYSDATE(6))");

        return $s;
    }

    private function verifyClock(PricingMySqlConnection $db, string $expression): void
    {
        $db->statement('SET SESSION timestamp=946684800');
        try {
            $sample = (int) $db->selectOne("SELECT {$expression} AS micros")->micros;
            if ($sample < (time() - 10) * 1000000 || $sample > (time() + 10) * 1000000) {
                throw new ProvisioningFailure('Reloj MySQL inseguro o desalineado. Revisar sysdate-is-now y UTC.');
            }
        } finally {
            $db->statement('SET SESSION timestamp=DEFAULT');
        }
    }

    private function verify(PricingMySqlConnection $db): int
    {
        if (! in_array(['TABLE', 'tekora_provisioning'], $this->objects($db), true)) {
            throw new ProvisioningFailure('Esquema parcial o ajeno. No se aplicó DDL; requiere revisión y recuperación administrativa.');
        }
        $rows = $db->table('tekora_provisioning')->limit(2)->get();
        $state = $rows->first();
        $versions = [];
        for ($version = 1; $version <= MySqlMigrationPlan::CURRENT; $version++) {
            $versions[MySqlMigrationPlan::digest($version)] = $version;
        }
        $version = $versions[$state->migration_sha256 ?? ''] ?? null;
        if ($rows->count() !== 1 || (int) $state->id !== 1 || $state->state !== 'ready' || $version === null) {
            throw new ProvisioningFailure('Instalación interrumpida o manifiesto incompatible. No se aplicó DDL ni se borraron datos.');
        }
        $this->verifyLedgerAndClock($db, $version);
        if ($state->schema_sha256 !== $this->schemaDigest($db)) {
            throw new ProvisioningFailure('La estructura instalada difiere del manifiesto sellado. Revisar antes de operar.');
        }

        return $version;
    }

    private function verifyLedgerAndClock(PricingMySqlConnection $db, int $version): void
    {
        $records = $db->table('migrations')->orderBy('id')->get(['migration', 'batch']);
        if ($records->pluck('migration')->all() !== MySqlMigrationPlan::identities($version)
            || $records->contains(fn (object $row, int $index) => (int) $row->batch !== match (true) {
                $index < 39 => 1, $index === 39 => 2, default => 3
            })) {
            throw new ProvisioningFailure('El libro de migraciones no coincide con las identidades y lotes de la versión sellada.');
        }
        $this->verifyClock($db, 'tekora_booking_now_us()');
        $clock = $db->table('reservation_hold_clock')->get(['id', 'high_water_us']);
        if ($clock->count() !== 1 || (int) $clock->first()->id !== 1
            || (int) $clock->first()->high_water_us > (int) $db->selectOne('SELECT tekora_booking_now_us() AS micros')->micros) {
            throw new ProvisioningFailure('Watermark ausente o adelantado. No ajustar el reloj ni el historial automáticamente.');
        }
    }

    private function objects(PricingMySqlConnection $db): array
    {
        $rows = $db->select("SELECT IF(TABLE_TYPE='VIEW','VIEW','TABLE') AS kind, TABLE_NAME AS name, ENGINE AS engine, NULL AS owner
            FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()
            UNION ALL SELECT 'TRIGGER', TRIGGER_NAME, NULL, DEFINER FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE()
            UNION ALL SELECT ROUTINE_TYPE, ROUTINE_NAME, NULL, DEFINER FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE()
            UNION ALL SELECT 'EVENT', EVENT_NAME, NULL, DEFINER FROM information_schema.EVENTS WHERE EVENT_SCHEMA=DATABASE()
            ORDER BY kind, name");
        $owners = $db->table('information_schema.VIEWS')->whereRaw('TABLE_SCHEMA=DATABASE()')->pluck('DEFINER', 'TABLE_NAME');
        $objects = [];
        foreach ($rows as $row) {
            if (($row->kind === 'TABLE' && $row->engine !== 'InnoDB')
                || ($row->kind === 'VIEW' && $owners->get($row->name) !== self::DEFINER)
                || (! in_array($row->kind, ['TABLE', 'VIEW'], true) && $row->owner !== self::DEFINER)) {
                throw new ProvisioningFailure('Motor de tabla o DEFINER incompatible en el esquema.');
            }
            $objects[] = [$row->kind, $row->name];
        }

        return $objects;
    }

    private function schemaDigest(PricingMySqlConnection $db): string
    {
        $definitions = [];
        $triggers = $db->table('information_schema.TRIGGERS')->whereRaw('TRIGGER_SCHEMA=DATABASE()')
            ->orderBy('TRIGGER_NAME')->get([
                'TRIGGER_NAME', 'DEFINER', 'EVENT_MANIPULATION', 'EVENT_OBJECT_TABLE', 'ACTION_ORDER',
                'ACTION_TIMING', 'ACTION_STATEMENT', 'SQL_MODE', 'CHARACTER_SET_CLIENT',
                'COLLATION_CONNECTION', 'DATABASE_COLLATION',
            ])->keyBy('TRIGGER_NAME');
        foreach ($this->objects($db) as [$type, $name]) {
            // mysqldump reconstructs the CREATE TRIGGER header. Compare its stored definition,
            // execution order and session metadata instead of the original header's whitespace.
            if ($type === 'TRIGGER') {
                $trigger = $triggers->get($name);
                if ($trigger === null || ! is_string($trigger->ACTION_STATEMENT) || $trigger->ACTION_STATEMENT === '') {
                    throw new ProvisioningFailure('Faltan metadatos de un trigger del esquema.');
                }
                $definitions[] = [$type, $name, $trigger];

                continue;
            }
            $identifier = '`'.str_replace('`', '``', $name).'`';
            $record = (array) $db->selectOne("SHOW CREATE {$type} {$identifier}");
            $ddl = $record['Create '.ucfirst(strtolower($type))] ?? null;
            if (! is_string($ddl) || $ddl === '') {
                throw new ProvisioningFailure('Se requiere acceso de metadatos para acreditar objetos del esquema.');
            }
            if ($type === 'TABLE') {
                // A logical restore can make a column's charset explicit. Its collation
                // already identifies the charset, so keep the collation in the seal.
                $ddl = preg_replace('/^(  `[^`]+` [a-z]+(?:\([^\n]*?\))?) CHARACTER SET utf8mb4(?= COLLATE utf8mb4_[a-z0-9_]+)/m', '$1', $ddl);
                $ddl = preg_replace('/^(\) ENGINE=InnoDB) AUTO_INCREMENT=\d+/m', '$1', $ddl);
            }
            $definitions[] = [$type, $name, $ddl, $record['sql_mode'] ?? null];
        }

        return hash('sha256', json_encode($definitions, JSON_THROW_ON_ERROR));
    }
}
