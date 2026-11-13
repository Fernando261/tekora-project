<?php

declare(strict_types=1);

namespace Tests\Support\BookingOwnershipLab;

use PDO;
use Throwable;

final class LabFixture
{
    private static array $retainedReaders = [];

    private array $manifest;

    private string $manifestHash;

    private ?Throwable $observationError = null;

    private function __construct(public readonly string $directory)
    {
        $this->refresh();
    }

    public static function create(string $case, string $variant = 'normal'): self
    {
        $root = self::runRoot();
        if (! preg_match('/\AT1-\d{2}(?:-[A-Za-z0-9_-]+)?\z/D', $case) || ! in_array($variant, ['normal', 'engine_abort'], true)) {
            throw new LabFailure('FixtureMismatch', 'INIT');
        }
        $directory = $root.'/'.$case.'-'.bin2hex(random_bytes(16));
        if (! mkdir($directory, 0700)) {
            throw new LabFailure('PathRejected', 'INIT');
        }
        $path = $directory.'/fixture.sqlite';
        $stream = fopen($path, 'x+b');
        if ($stream === false) {
            throw new LabFailure('PathRejected', 'INIT');
        }
        chmod($path, 0600);
        $stat = fstat($stream);
        fclose($stream);
        $manifest = ['case' => $case, 'epoch' => bin2hex(random_bytes(16)), 'variant' => $variant, 'db' => $path,
            'dev' => $stat['dev'], 'ino' => $stat['ino'], 'schema_hash' => null, 'profile' => 'T1-Linux-DELETE-FULL-v1',
            'resources' => self::resources()];
        self::exclusiveJson($directory.'/owner.json', $manifest);

        return new self($directory);
    }

    public static function load(string $directory, string $expectedHash): self
    {
        $fixture = new self($directory);
        if (! hash_equals($fixture->manifestHash, $expectedHash)) {
            throw new LabFailure('FixtureMismatch', 'OPEN_EXISTING_OWNED');
        }

        return $fixture;
    }

    public static function runRoot(): string
    {
        $root = getenv('T1_RUN_ROOT');
        if (! is_string($root) || $root === '' || realpath($root) !== $root || is_link($root) || ! is_dir($root)
            || (fileperms($root) & 0777) !== 0700) {
            throw new LabFailure('PathRejected', 'INIT');
        }

        return $root;
    }

    public function initialize(): void
    {
        $this->verifyPaths();
        if ($this->manifest['schema_hash'] !== null || filesize($this->path()) !== 0) {
            throw new LabFailure('ExistingFixtureRejected', 'INIT');
        }
        $pdo = $this->connect(false);
        foreach ($this->schema() as $ddl) {
            $pdo->exec($ddl);
        }
        $statement = $pdo->prepare('INSERT INTO coordination VALUES (1, ?, 0, NULL)');
        $statement->execute([$this->epoch()]);
        $statement = null;
        $this->manifest['schema_hash'] = self::schemaHash($pdo);
        $pdo = null;
        $temporary = $this->directory.'/owner.ready.json';
        self::exclusiveJson($temporary, $this->manifest);
        if (! rename($temporary, $this->directory.'/owner.json')) {
            throw new LabFailure('EvidenceFailure', 'INIT');
        }
        $this->refresh();
    }

    public static function fresh(string $case, string $variant = 'normal'): self
    {
        $fixture = self::create($case, $variant);
        $fixture->initialize();

        return $fixture;
    }

    public function refresh(): void
    {
        $root = self::runRoot();
        if (dirname($this->directory) !== $root || realpath($this->directory) !== $this->directory || is_link($this->directory)) {
            throw new LabFailure('PathRejected', 'OPEN_EXISTING_OWNED');
        }
        $path = $this->directory.'/owner.json';
        if (is_link($path) || ! is_file($path) || lstat($path)['nlink'] !== 1) {
            throw new LabFailure('PathRejected', 'OPEN_EXISTING_OWNED');
        }
        $raw = file_get_contents($path);
        $this->manifest = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        $this->manifestHash = hash('sha256', $raw);
        $this->verifyPaths();
    }

    public function verifyPaths(): void
    {
        $root = self::runRoot();
        if (dirname($this->directory) !== $root || realpath($this->directory) !== $this->directory
            || $this->manifest['db'] !== $this->directory.'/fixture.sqlite'
            || ! preg_match('/\A[a-f0-9]{32}\z/D', $this->manifest['epoch']) || $this->manifest['resources'] !== self::resources()) {
            throw new LabFailure('PathRejected', 'OPEN_EXISTING_OWNED');
        }
        foreach (scandir($this->directory) as $name) {
            if (! in_array($name, ['.', '..'], true) && ! in_array($name, $this->manifest['resources'], true)) {
                throw new LabFailure('UnexpectedFixtureResource', 'OPEN_EXISTING_OWNED');
            }
        }
        $current = '';
        foreach (explode('/', ltrim($this->path(), '/')) as $component) {
            $current .= '/'.$component;
            if (is_link($current)) {
                throw new LabFailure('PathRejected', 'OPEN_EXISTING_OWNED');
            }
        }
        clearstatcache(true, $this->path());
        $stat = @lstat($this->path());
        if ($stat === false || ! is_file($this->path()) || $stat['nlink'] !== 1
            || $stat['dev'] !== $this->manifest['dev'] || $stat['ino'] !== $this->manifest['ino']
            || hash_file('sha256', $this->directory.'/owner.json') !== $this->manifestHash) {
            throw new LabFailure('FixtureMismatch', 'OPEN_EXISTING_OWNED');
        }
    }

    public function path(): string
    {
        return $this->manifest['db'];
    }

    public function epoch(): string
    {
        return $this->manifest['epoch'];
    }

    public function hash(): string
    {
        return $this->manifestHash;
    }

    public function variant(): string
    {
        return $this->manifest['variant'];
    }

    /** Harness-only acquisition. Never passed to a participant or reused. */
    public function connect(bool $readOnly): PDO
    {
        if (! $readOnly) {
            PrivateConnectionFactory::assertAdmission();
        }
        $this->verifyPaths();
        $flags = $readOnly ? PDO::SQLITE_OPEN_READONLY : PDO::SQLITE_OPEN_READWRITE;
        $pdo = new PDO('sqlite:'.$this->path(), options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_PERSISTENT => false, PDO::SQLITE_ATTR_OPEN_FLAGS => $flags]);
        $pdo->exec('PRAGMA foreign_keys=ON');
        $pdo->exec('PRAGMA synchronous=FULL');
        $pdo->exec('PRAGMA busy_timeout=0');
        $this->assertProfile($pdo);
        $this->verifyPaths();

        return $pdo;
    }

    public function assertProfile(PDO $pdo): void
    {
        if (! in_array(PHP_VERSION, ['8.2.35', '8.4.27'], true)
            || ini_get('zend.exception_ignore_args') !== '1'
            || $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite'
            || $pdo->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION
            || $pdo->getAttribute(PDO::ATTR_PERSISTENT) !== false
            || $pdo->query('SELECT sqlite_version()')->fetchColumn() !== '3.40.1'
            || (int) $pdo->query('PRAGMA foreign_keys')->fetchColumn() !== 1
            || $pdo->query('PRAGMA journal_mode')->fetchColumn() !== 'delete'
            || (int) $pdo->query('PRAGMA synchronous')->fetchColumn() !== 2
            || (int) $pdo->query('PRAGMA busy_timeout')->fetchColumn() !== 0) {
            throw new LabFailure('ProfileRejected', 'PREFLIGHT');
        }
        if ($this->manifest['schema_hash'] !== null && self::schemaHash($pdo) !== $this->manifest['schema_hash']) {
            throw new LabFailure('FixtureMismatch', 'PREFLIGHT');
        }
    }

    private static function schemaHash(PDO $pdo): string
    {
        return hash('sha256', json_encode($pdo->query('SELECT type,name,tbl_name,sql FROM sqlite_schema ORDER BY type,name')->fetchAll(PDO::FETCH_NUM), JSON_THROW_ON_ERROR));
    }

    public function observe(string $phase, bool $known = true, ?string $fault = null): array
    {
        if (! in_array($phase, ['P', 'S', 'U', 'DURING'], true) || ! in_array($fault, [null, 'busy', 'open', 'close_false', 'begin_uncertain'], true)) {
            throw new LabFailure('UnsupportedFaultPoint', 'OBSERVE');
        }
        $result = ['snapshot_id' => bin2hex(random_bytes(16)), 'phase' => $phase, 'pid' => getmypid(), 'epoch' => $this->epoch(),
            'manifest_hash' => $this->hash(), 'php' => PHP_VERSION, 'trace_arguments_ignored' => ini_get('zend.exception_ignore_args') === '1', 'time_ns' => hrtime(true), 'injected' => $fault !== null, 'read_complete' => false];
        $pdo = null;
        $readerRequested = false;
        $readerStarted = false;
        $readerClosed = false;
        $readerCloseRequested = false;
        $result['reader_close_calls'] = 0;
        $this->observationError = null;
        try {
            if ($fault === 'busy') {
                throw FaultPlan::diagnostic('HY000', 5, 'database is locked');
            }
            if ($fault === 'open') {
                throw new LabFailure('ObservationOpenFailure', 'OBSERVE');
            }
            $pdo = $this->connect(true);
            $result['pdo_id'] = spl_object_id($pdo);
            $result['foreign_keys'] = $pdo->query('PRAGMA foreign_keys')->fetchAll(PDO::FETCH_NUM);
            $readerRequested = true;
            if ($pdo->beginTransaction() !== true) {
                throw new LabFailure('ObservationBeginUnknown', 'OBSERVE');
            }
            if ($fault === 'begin_uncertain') {
                throw new LabFailure('ObservationBeginUnknown', 'OBSERVE');
            }
            $readerStarted = true;
            $result['integrity_check'] = $pdo->query('PRAGMA integrity_check')->fetchAll(PDO::FETCH_NUM);
            $result['foreign_key_check'] = $pdo->query('PRAGMA foreign_key_check')->fetchAll(PDO::FETCH_NUM);
            $result['tables'] = [];
            foreach (['coordination', 'probe_a', 'probe_b', 'probe_outbox', 'probe_receipt'] as $table) {
                $rows = $pdo->query('SELECT * FROM '.$table.' ORDER BY 1')->fetchAll(PDO::FETCH_ASSOC);
                if ($table === 'coordination') {
                    foreach ($rows as &$row) {
                        $row['owner_nonce'] = $row['owner_nonce'] === null ? null : hash('sha256', $row['owner_nonce']);
                    }
                    unset($row);
                }
                $result['tables'][$table] = $rows;
            }
            $readerCloseRequested = true;
            $result['reader_close_calls']++;
            if ($fault === 'close_false' || $pdo->rollBack() !== true) {
                throw new LabFailure('ObservationCloseUnknown', 'OBSERVE');
            }
            $readerClosed = true;
            $result['read_complete'] = true;
            $structural = count($result['foreign_keys']) === 1 && count($result['foreign_keys'][0]) === 1
                && (int) $result['foreign_keys'][0][0] === 1 && $result['integrity_check'] === [['ok']] && $result['foreign_key_check'] === [];
            $functional = self::validGraph($result['tables']) && count($result['tables']['coordination']) === 1
                && $result['tables']['coordination'][0]['epoch'] === $this->epoch();
            $result['structural_status'] = $structural ? 'PASS' : 'FAIL';
            $result['functional_status'] = $functional ? 'PASS' : 'FAIL';
            $result['status'] = ! $structural || ! $functional ? 'FAIL' : ($known ? 'PASS' : 'UNKNOWN');
        } catch (Throwable $error) {
            $this->observationError = $error;
            $result['status'] = 'BLOCKED';
            $result['error'] = LabFailure::from($error, 'OBSERVE', 'ObservationUnavailable')->jsonSerialize();
            if ($pdo !== null && $readerStarted && ! $readerClosed && ! $readerCloseRequested) {
                try {
                    // Positive return of this reader's BEGIN, never merely a boolean diagnostic.
                    $readerCloseRequested = true;
                    $result['reader_close_calls']++;
                    $readerClosed = $pdo->rollBack() === true;
                } catch (Throwable $closeError) {
                    $result['secondary_error'] = LabFailure::from($closeError, 'OBSERVE', 'ObservationCloseUnknown')->jsonSerialize();
                }
            }
        } finally {
            if ($pdo !== null && $readerRequested && ! $readerClosed) {
                self::$retainedReaders[$result['snapshot_id']] = $pdo;
                PrivateConnectionFactory::blockWorker();
                $result['cleanup_state'] = 'retained_reader_quarantine';
                if (($result['status'] ?? null) !== 'FAIL') {
                    $result['status'] = 'UNKNOWN';
                }
            }
            $pdo = null;
        }
        $this->record('observations', $result);

        return $result;
    }

    public function observationError(): ?Throwable
    {
        return $this->observationError;
    }

    public static function weakRetainedReader(string $snapshotId): ?\WeakReference
    {
        return isset(self::$retainedReaders[$snapshotId]) ? \WeakReference::create(self::$retainedReaders[$snapshotId]) : null;
    }

    public static function validGraph(array $tables): bool
    {
        $receipts = $tables['probe_receipt'];
        foreach (['probe_a', 'probe_b', 'probe_outbox'] as $table) {
            if (count($tables[$table]) !== count($receipts)) {
                return false;
            }
        }
        foreach ($receipts as $receipt) {
            foreach (['probe_a' => 'a_value', 'probe_b' => 'b_value', 'probe_outbox' => 'event_key'] as $table => $field) {
                $matches = array_values(array_filter($tables[$table], fn ($row) => $row['probe_key'] === $receipt['probe_key'] && $row['invocation_id'] === $receipt['invocation_id'] && $row[$field] === $receipt[$field]));
                if (count($matches) !== 1 || ($table === 'probe_outbox' && (int) $matches[0]['delivered'] !== 0)) {
                    return false;
                }
            }
        }

        return true;
    }

    public function record(string $kind, array $data): void
    {
        if (! in_array($kind, ['observations', 'runs', 'processes', 'release'], true)) {
            throw new LabFailure('EvidenceFailure', 'EVIDENCE');
        }
        $path = $this->directory.'/'.$kind.'.jsonl';
        if (is_link($path) || is_dir($path) || file_put_contents($path, json_encode($data, JSON_THROW_ON_ERROR)."\n", FILE_APPEND | LOCK_EX) === false) {
            throw new LabFailure('EvidenceFailure', 'EVIDENCE');
        }
        chmod($path, 0600);
    }

    private static function exclusiveJson(string $path, array $data): void
    {
        $file = fopen($path, 'xb');
        if ($file === false) {
            throw new LabFailure('PathRejected', 'INIT');
        }
        chmod($path, 0600);
        fwrite($file, json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
        fclose($file);
    }

    private static function resources(): array
    {
        $resources = ['owner.json', 'owner.ready.json', 'fixture.sqlite', 'fixture.sqlite-journal', 'fixture.sqlite.own-backup',
            'observations.jsonl', 'runs.jsonl', 'processes.jsonl', 'release.jsonl'];
        foreach (['release', 'released', 'ready-holder', 'ready-left', 'ready-right', 'go-left', 'go-right', 'a-left', 'tried-right', 'committed-left'] as $signal) {
            $resources[] = $signal.'.signal';
            $resources[] = $signal.'.signal.pending';
        }

        return $resources;
    }

    private function schema(): array
    {
        $schema = [
            'CREATE TABLE coordination (id INTEGER PRIMARY KEY CHECK(id=1), epoch TEXT NOT NULL, admission_version INTEGER NOT NULL CHECK(admission_version>=0), owner_nonce TEXT)',
            'CREATE TABLE probe_a (probe_key TEXT PRIMARY KEY, a_value INTEGER NOT NULL, invocation_id TEXT NOT NULL, UNIQUE(probe_key,invocation_id,a_value), FOREIGN KEY(probe_key,invocation_id) REFERENCES probe_receipt(probe_key,invocation_id) DEFERRABLE INITIALLY DEFERRED)',
            'CREATE TABLE probe_b (probe_key TEXT PRIMARY KEY, b_value INTEGER NOT NULL, invocation_id TEXT NOT NULL, UNIQUE(probe_key,invocation_id,b_value), FOREIGN KEY(probe_key,invocation_id) REFERENCES probe_receipt(probe_key,invocation_id) DEFERRABLE INITIALLY DEFERRED)',
            "CREATE TABLE probe_outbox (probe_key TEXT PRIMARY KEY, invocation_id TEXT NOT NULL, event_key TEXT NOT NULL, kind TEXT NOT NULL CHECK(kind='probe'), delivered INTEGER NOT NULL CHECK(delivered=0), UNIQUE(probe_key,invocation_id), UNIQUE(probe_key,invocation_id,event_key), FOREIGN KEY(probe_key,invocation_id) REFERENCES probe_receipt(probe_key,invocation_id) DEFERRABLE INITIALLY DEFERRED)",
            'CREATE TABLE probe_receipt (probe_key TEXT PRIMARY KEY, invocation_id TEXT NOT NULL, a_value INTEGER NOT NULL, b_value INTEGER NOT NULL, event_key TEXT NOT NULL, format_version INTEGER NOT NULL CHECK(format_version=1), UNIQUE(probe_key,invocation_id), FOREIGN KEY(probe_key,invocation_id,a_value) REFERENCES probe_a(probe_key,invocation_id,a_value) DEFERRABLE INITIALLY DEFERRED, FOREIGN KEY(probe_key,invocation_id,b_value) REFERENCES probe_b(probe_key,invocation_id,b_value) DEFERRABLE INITIALLY DEFERRED, FOREIGN KEY(probe_key,invocation_id,event_key) REFERENCES probe_outbox(probe_key,invocation_id,event_key) DEFERRABLE INITIALLY DEFERRED)',
        ];
        foreach (['probe_a', 'probe_b', 'probe_outbox', 'probe_receipt'] as $table) {
            $schema[] = "CREATE TRIGGER {$table}_insert BEFORE INSERT ON {$table} WHEN EXISTS(SELECT 1 FROM {$table} WHERE probe_key=NEW.probe_key) BEGIN SELECT RAISE(ABORT,'duplicate_probe'); END";
            foreach (['UPDATE', 'DELETE'] as $operation) {
                $schema[] = 'CREATE TRIGGER '.$table.'_'.strtolower($operation).' BEFORE '.$operation.' ON '.$table." BEGIN SELECT RAISE(ABORT,'immutable_probe'); END";
            }
        }
        if ($this->variant() === 'engine_abort') {
            $schema[] = "CREATE TRIGGER abort_probe_b BEFORE INSERT ON probe_b WHEN NEW.b_value=-777 BEGIN SELECT RAISE(ROLLBACK,'synthetic_engine_abort'); END";
        }

        return $schema;
    }
}
