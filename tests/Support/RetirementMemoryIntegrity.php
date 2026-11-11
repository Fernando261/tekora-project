<?php

namespace Tests\Support;

use PDO;
use RuntimeException;

/** Private PHPUnit helpers, with no file-backed database or application entry point. */
trait RetirementMemoryIntegrity
{
    private const PROTECTED = [
        'activity_notifications', 'cache', 'cache_locks', 'failed_jobs', 'inquiry_notifications',
        'job_batches', 'jobs', 'migrations', 'password_reset_tokens', 'personal_access_tokens',
        'provider_availability_settings', 'provider_blocked_dates', 'provider_profiles',
        'provider_weekly_intervals', 'reservation_events', 'role_user', 'roles',
        'service_categories', 'service_inquiries', 'service_inquiry_messages', 'service_inquiry_reads',
        'service_reservations', 'service_reviews', 'services', 'sessions', 'users',
    ];

    private function guardMemoryPdo(PDO $pdo): void
    {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            throw new RuntimeException('SQLite memory PDO required');
        }
        foreach ($pdo->query('PRAGMA database_list')->fetchAll(PDO::FETCH_ASSOC) as $database) {
            if ($database['file'] !== '' || ! in_array($database['name'], ['main', 'temp'], true)) {
                throw new RuntimeException('Attached databases forbidden');
            }
        }
        if ((int) $pdo->query('PRAGMA foreign_keys')->fetchColumn() !== 1) {
            throw new RuntimeException('Foreign keys must remain enabled');
        }
    }

    private function quoteIdentifier(string $name): string
    {
        return '"'.str_replace('"', '""', $name).'"';
    }

    private function memoryManifest(?PDO $pdo = null): array
    {
        if ($pdo === null) {
            $this->memoryOnly();
            $pdo = \Illuminate\Support\Facades\DB::connection()->getPdo();
        }
        $this->guardMemoryPdo($pdo);
        $query = fn (string $sql) => $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        // Compare the reserved prefix literally: LIKE 'sqlite_%' also matches
        // valid application names such as sqliteX_cross because _ is a wildcard.
        $objects = $query("SELECT type,name,tbl_name,sql FROM sqlite_master WHERE substr(name,1,7) <> 'sqlite_' COLLATE NOCASE ORDER BY type,name");
        $sequences = $query('SELECT name,typeof(name) AS name_type,seq AS value,typeof(seq) AS storage_type FROM sqlite_sequence');
        usort($sequences, fn ($a, $b) => strcmp(serialize($a), serialize($b)));
        $tables = [];
        foreach ($objects as $object) {
            if ($object['type'] !== 'table') {
                continue;
            }
            $name = $object['name'];
            $quoted = $this->quoteIdentifier($name);
            $columns = $query('PRAGMA table_xinfo('.$quoted.')');
            $indexes = [];
            foreach ($query('PRAGMA index_list('.$quoted.')') as $index) {
                // seq is an enumeration position, not an index property. Key order
                // remains exact through index_xinfo.seqno; no constraint is omitted.
                unset($index['seq']);
                $index['columns'] = $query('PRAGMA index_xinfo('.$this->quoteIdentifier($index['name']).')');
                $index['sql'] = null;
                foreach ($objects as $candidate) {
                    if ($candidate['type'] === 'index' && $candidate['name'] === $index['name']) {
                        $index['sql'] = $candidate['sql'];
                    }
                }
                $indexes[$index['name']] = $index;
            }
            ksort($indexes);
            $types = [];
            foreach ($columns as $i => $column) {
                $types[] = 'typeof('.$this->quoteIdentifier($column['name']).') AS "__storage_type_'.$i.'"';
            }
            $rows = [];
            foreach ($query('SELECT *,'.implode(',', $types).' FROM '.$quoted) as $row) {
                $rows[] = ['values' => array_slice($row, 0, count($columns), true),
                    'storage_types' => array_values(array_slice($row, count($columns)))];
            }
            usort($rows, fn ($a, $b) => strcmp(serialize($a), serialize($b)));
            $tables[$name] = ['sql' => $object['sql'], 'columns' => $columns,
                'foreign_keys' => $query('PRAGMA foreign_key_list('.$quoted.')'), 'indexes' => $indexes,
                'triggers' => array_values(array_filter($objects, fn ($o) => $o['type'] === 'trigger' && $o['tbl_name'] === $name)),
                'rows' => $rows,
                'sequence' => array_values(array_filter($sequences, fn ($entry) => $entry['name_type'] === 'text' && $entry['name'] === $name))];
        }
        ksort($tables);

        return ['tables' => $tables, 'objects' => $objects, 'sequences' => $sequences,
            'temp_objects' => $query("SELECT type,name,tbl_name,sql FROM sqlite_temp_master WHERE substr(name,1,7) <> 'sqlite_' COLLATE NOCASE ORDER BY type,name")];
    }

    private function schemaPart(array $manifest): array
    {
        // Data is separated only for schema preflight. Full preservation and
        // restore comparisons retain raw sequence entries and storage classes.
        unset($manifest['sequences']);
        foreach ($manifest['tables'] as &$table) {
            unset($table['rows'], $table['sequence']);
        }

        return $manifest;
    }

    private function validateSequences(array $manifest): void
    {
        $byTable = array_fill_keys(array_keys($manifest['tables']), []);
        foreach ($manifest['sequences'] as $entry) {
            if (! is_array($entry) || array_keys($entry) !== ['name', 'name_type', 'value', 'storage_type']
                || $entry['name_type'] !== 'text' || ! is_string($entry['name'])
                || ! array_key_exists($entry['name'], $byTable)
                || $byTable[$entry['name']] !== []
                || $entry['storage_type'] !== 'integer' || ! is_int($entry['value']) || $entry['value'] < 0) {
                throw new RuntimeException('Invalid sqlite_sequence entry: stop before retirement or restore');
            }
            $byTable[$entry['name']][] = $entry;
        }
        foreach ($manifest['tables'] as $name => $table) {
            if ($table['sequence'] !== $byTable[$name]) {
                throw new RuntimeException('Invalid sqlite_sequence snapshot: inconsistent table entries');
            }
            // This SQL is the exact trusted 37-migration catalog, checked before
            // this validator. No arbitrary schema is approved by parsing SQL.
            $auto = preg_match('/\bAUTOINCREMENT\b/i', $table['sql']) === 1;
            if (! $auto) {
                if ($table['sequence'] !== []) {
                    throw new RuntimeException('Invalid sqlite_sequence entry for a non-autoincrement table');
                }

                continue;
            }
            $primary = array_values(array_filter($table['columns'], fn ($column) => $column['pk'] !== 0));
            if (count($primary) !== 1 || $primary[0]['type'] !== 'INTEGER') {
                throw new RuntimeException('Invalid sqlite_sequence primary key');
            }
            $max = 0;
            foreach ($table['rows'] as $record) {
                $id = $record['values'][$primary[0]['name']];
                if (! is_int($id)) {
                    throw new RuntimeException('Invalid sqlite_sequence rowid representation');
                }
                $max = max($max, $id);
            }
            if (($table['rows'] !== [] && $table['sequence'] === [])
                || ($table['sequence'] !== [] && $table['sequence'][0]['value'] < $max)) {
                throw new RuntimeException('Invalid sqlite_sequence high-water mark');
            }
        }
    }

    private function protectedManifest(array $manifest): array
    {
        $names = array_keys($manifest['tables']);
        $protected = array_values(array_diff($names, self::TABLES));
        if ($protected !== self::PROTECTED) {
            throw new RuntimeException('Exact 26 protected tables required, including empty tables');
        }

        return array_intersect_key($manifest['tables'], array_flip(self::PROTECTED));
    }

    private function reviewedSchemaAt(array $absent): array
    {
        $schema = $this->schemaPart($this->reviewedManifest);
        foreach ($absent as $table) {
            unset($schema['tables'][$table]);
        }
        $schema['objects'] = array_values(array_filter($schema['objects'], fn ($o) => ! in_array($o['tbl_name'], $absent, true)));

        return $schema;
    }

    private function restoreLogicalMemory(array $source, ?\Closure $afterLoad = null): PDO
    {
        $this->memoryOnly();
        if ($this->schemaPart($source) !== $this->reviewedSchemaAt([])
            || $source['tables']['migrations']['rows'] !== $this->reviewedManifest['tables']['migrations']['rows']) {
            throw new RuntimeException('Unreviewed restore schema');
        }
        $this->validateSequences($source);
        $copy = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $copy->exec('PRAGMA foreign_keys = ON');
        $this->guardMemoryPdo($copy);
        $copy->beginTransaction();
        try {
            foreach ($source['tables'] as $table) {
                $this->guardMemoryPdo($copy);
                $copy->exec($table['sql']);
            }
            // Composite FK parents can depend on separately-created UNIQUE
            // indexes (conversation/message identity in migration 32). They
            // must exist before inserting children, with FK still enabled.
            foreach ($source['objects'] as $object) {
                if ($object['type'] === 'index' && $object['sql'] !== null) {
                    $this->guardMemoryPdo($copy);
                    $copy->exec($object['sql']);
                }
            }
            // Load parents before children with FK enforcement ON throughout.
            // Triggers are created after loading, never disabled or removed.
            $remaining = $source['tables'];
            while ($remaining) {
                $progress = false;
                foreach ($remaining as $name => $table) {
                    $parents = array_diff(array_column($table['foreign_keys'], 'table'), [$name]);
                    if (array_intersect($parents, array_keys($remaining))) {
                        continue;
                    }
                    foreach ($table['rows'] as $record) {
                        $this->guardMemoryPdo($copy);
                        $row = $record['values'];
                        $columns = implode(',', array_map($this->quoteIdentifier(...), array_keys($row)));
                        $statement = $copy->prepare('INSERT INTO '.$this->quoteIdentifier($name).' ('.$columns.') VALUES ('.implode(',', array_fill(0, count($row), '?')).')');
                        foreach (array_values($row) as $index => $value) {
                            $type = $record['storage_types'][$index];
                            $statement->bindValue($index + 1, $value, $value === null ? PDO::PARAM_NULL : ($type === 'blob' ? PDO::PARAM_LOB : (is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR)));
                        }
                        $statement->execute();
                        $statement->closeCursor();
                    }
                    if ($table['sequence'] !== []) {
                        $statement = $copy->prepare('SELECT COUNT(*) FROM sqlite_sequence WHERE name = ?');
                        $statement->execute([$name]);
                        $exists = (int) $statement->fetchColumn();
                        $statement->closeCursor();
                        $sql = $exists ? 'UPDATE sqlite_sequence SET seq = ? WHERE name = ?' : 'INSERT INTO sqlite_sequence(seq,name) VALUES (?,?)';
                        $statement = $copy->prepare($sql);
                        $statement->bindValue(1, $table['sequence'][0]['value'], PDO::PARAM_INT);
                        $statement->bindValue(2, $name, PDO::PARAM_STR);
                        $statement->execute();
                        $statement->closeCursor();
                    }
                    unset($remaining[$name]);
                    $progress = true;
                }
                if (! $progress) {
                    throw new RuntimeException('Unresolved restore dependency cycle');
                }
            }
            foreach ($source['objects'] as $object) {
                if ($object['type'] === 'trigger' && $object['sql'] !== null) {
                    $this->guardMemoryPdo($copy);
                    $copy->exec($object['sql']);
                }
            }
            if ($afterLoad) {
                $afterLoad($copy);
            }
            $this->guardMemoryPdo($copy);
            if ($copy->query('PRAGMA foreign_key_check')->fetchAll() !== []
                || $copy->query('PRAGMA integrity_check')->fetchColumn() !== 'ok'
                || $this->memoryManifest($copy) !== $source) {
                throw new RuntimeException('Restored manifest mismatch; destination rejected');
            }
            $copy->commit();
        } catch (\Throwable $exception) {
            $copy->rollBack();
            throw $exception;
        }

        return $copy;
    }

    private function recordRehearsalEvidence(string $case, array $data): void
    {
        if (getenv('TEKORA_W62CD_EVIDENCE') !== '1') {
            return;
        }
        $directory = base_path('storage/framework/testing/w62cd-evidence');
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        // Callers supply summaries/schema only: never raw users, credentials,
        // messages, auth tokens or image bytes. This path cannot select a DB.
        file_put_contents($directory.'/'.$case.'.json', json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
    }

    private function manifestSummary(array $manifest): array
    {
        $tables = [];
        foreach ($manifest['tables'] as $name => $table) {
            $tables[$name] = ['row_count' => count($table['rows']), 'sequence' => $table['sequence'],
                'synthetic_state_sha256' => hash('sha256', serialize($table)),
                'columns' => $table['columns'], 'foreign_keys' => $table['foreign_keys'],
                'indexes' => $table['indexes'], 'triggers' => $table['triggers'], 'sql' => $table['sql']];
        }

        return ['scope' => 'synthetic SQLite memory state; no media hashes or raw row values',
            'tables' => $tables, 'objects' => $manifest['objects'], 'temp_objects' => $manifest['temp_objects'],
            'sequences' => $manifest['sequences'],
            // This sole row export is migration metadata (names/IDs/batches),
            // never user, message, credential or token values.
            'migration_ledger' => array_column($manifest['tables']['migrations']['rows'], 'values')];
    }
}
