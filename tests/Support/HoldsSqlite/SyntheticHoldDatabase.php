<?php

declare(strict_types=1);

namespace Tests\Support\HoldsSqlite;

use Closure;
use LogicException;
use PDO;
use Throwable;
use WeakMap;

/** Only newly created lab resources; no arbitrary existing-path/DSN constructor or cleanup. */
final class SyntheticHoldDatabase
{
    private WeakMap $connections;

    private array $busy = [];

    private function __construct(private readonly array $ticket)
    {
        $this->connections = new WeakMap;
    }

    private static function parent(): array
    {
        $parent = getenv('TEKORA_B222_LAB_PARENT');
        $run = getenv('TEKORA_B222_RUN_TOKEN');
        if (! is_string($parent) || ! is_string($run) || ! preg_match('/\A[0-9a-f]{64}\z/', $run) || realpath($parent) !== $parent || ! is_dir($parent) || is_link($parent)) {
            throw new LogicException('An explicit canonical test parent/run is required.');
        }
        self::identity($parent, 0040000, 0700);

        return [$parent, $run];
    }

    private static function identity(string $path, int $type, int $permissions): array
    {
        if (! function_exists('posix_geteuid')) {
            throw new LogicException('This lab capability requires POSIX identity checks; platform validation pending.');
        }
        clearstatcache(true, $path);
        $stat = lstat($path);
        if ($stat === false || is_link($path) || ($stat['mode'] & 0170000) !== $type || ($stat['mode'] & 0777) !== $permissions || $stat['uid'] !== posix_geteuid()) {
            throw new LogicException('Unknown synthetic resource identity.');
        }

        return ['dev' => $stat['dev'], 'ino' => $stat['ino']];
    }

    public static function create(): self
    {
        [$parent, $run] = self::parent();
        $directory = $parent.'/tekora-b222-'.bin2hex(random_bytes(16));
        if (! mkdir($directory, 0700)) {
            throw new LogicException('Cannot create a new lab directory.');
        }
        $path = $directory.'/holds.sqlite';
        $file = fopen($path, 'x+b');
        if ($file === false) {
            throw new LogicException('Cannot exclusively create the new lab file.');
        }
        try {
            if (! chmod($path, 0600)) {
                throw new LogicException('Cannot restrict new lab permissions.');
            }
            $stat = fstat($file);
            if ($stat === false) {
                throw new LogicException('Cannot establish new lab identity.');
            }
            $identity = ['dev' => $stat['dev'], 'ino' => $stat['ino']];
        } finally {
            fclose($file);
        }
        $ticket = ['directory' => $directory, 'path' => $path, 'run' => $run, 'token' => bin2hex(random_bytes(32)), 'directory_identity' => self::identity($directory, 0040000, 0700), 'file_identity' => $identity, 'schema_sha256' => hash_file('sha256', __DIR__.'/schema.sql')];
        $manifest = fopen($directory.'/capability.json', 'x+b');
        if ($manifest === false) {
            throw new LogicException('Cannot create new process capability.');
        }
        try {
            if (! chmod($directory.'/capability.json', 0600)) {
                throw new LogicException('Cannot restrict capability.');
            }
            $json = json_encode($ticket, JSON_THROW_ON_ERROR);
            if (fwrite($manifest, $json) !== strlen($json)) {
                throw new LogicException('Incomplete capability write.');
            }
        } finally {
            fclose($manifest);
        }
        $database = new self($ticket);
        $pdo = $database->connection(true);
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $pdo->exec(file_get_contents(__DIR__.'/schema.sql'));
            $pdo->exec('PRAGMA user_version=222');
            $pdo->exec('COMMIT');
        } catch (Throwable $error) {
            $pdo->exec('ROLLBACK');
            throw $error;
        }

        return $database;
    }

    /** Reopen only a capability issued in THIS test run; workers never adopt a supplied DSN. */
    public static function resume(array $ticket): self
    {
        $database = new self($ticket);
        $database->verify();

        return $database;
    }

    public function ticket(): array
    {
        $this->verify();

        return $this->ticket;
    }

    private function verify(): void
    {
        [$parent, $run] = self::parent();
        $t = $this->ticket;
        if (array_keys($t) !== ['directory', 'path', 'run', 'token', 'directory_identity', 'file_identity', 'schema_sha256'] || ! is_string($t['directory']) || ! preg_match('~\A'.preg_quote($parent, '~').'/tekora-b222-[0-9a-f]{32}\z~', $t['directory']) || $t['path'] !== $t['directory'].'/holds.sqlite' || $t['run'] !== $run || ! is_string($t['token']) || ! preg_match('/\A[0-9a-f]{64}\z/', $t['token']) || $t['schema_sha256'] !== hash_file('sha256', __DIR__.'/schema.sql')) {
            throw new LogicException('Invalid run capability.');
        }
        if (self::identity($t['directory'], 0040000, 0700) !== $t['directory_identity'] || self::identity($t['path'], 0100000, 0600) !== $t['file_identity']) {
            throw new LogicException('Lab identity changed.');
        }
        self::identity($t['directory'].'/capability.json', 0100000, 0600);
        $manifest = json_decode(file_get_contents($t['directory'].'/capability.json'), true, flags: JSON_THROW_ON_ERROR);
        if ($manifest !== $t) {
            throw new LogicException('Lab capability mismatch.');
        }
    }

    private function connection(bool $initializing = false, int $attempts = 3, ?Closure $onBusy = null): PDO
    {
        $contention = new SqliteHoldContention($attempts, $onBusy);
        try {
            $pdo = $contention->run('connect', function () use ($initializing) {
                // No business transaction or schema write has started. A failed attempt owns
                // only this local PDO, which is discarded before opening another one.
                $this->verify();
                if (PHP_INT_SIZE !== 8) {
                    throw new LogicException('64-bit PHP is required.');
                }
                $pdo = new PDO('sqlite:'.$this->ticket['path'], options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_STRINGIFY_FETCHES => false, PDO::ATTR_PERSISTENT => false]);
                $this->verify();
                // Connection-local, idempotent settings. Never replay schema or application DML.
                $pdo->exec('PRAGMA foreign_keys=ON');
                $pdo->exec('PRAGMA busy_timeout=0');
                $pdo->exec('PRAGMA synchronous=FULL');
                if (version_compare($pdo->query('SELECT sqlite_version()')->fetchColumn(), '3.38.0', '<')) {
                    throw new LogicException('SQLite >=3.38 required.');
                }
                $this->validate($pdo, $initializing);

                return $pdo;
            });
            // An unsuccessfully prepared PDO never acquires a registered capability.
            $this->connections[$pdo] = $contention->events();

            return $pdo;
        } finally {
            // Preserve evidence even when connect exhausts its budget and returns no PDO.
            $this->busy = array_merge($this->busy, $contention->events());
        }
    }

    public function connect(int $attempts = 3, ?Closure $onBusy = null): PDO
    {
        return $this->connection(false, $attempts, $onBusy);
    }

    public function check(PDO $pdo, bool $initializing = false): void
    {
        if (! $this->owns($pdo)) {
            throw new LogicException('Foreign or invalidated connection.');
        }
        // Context-specific retry/rollback belongs to the caller, not to active transactions.
        $this->validate($pdo, $initializing);
    }

    private function validate(PDO $pdo, bool $initializing): void
    {
        $this->verify();
        foreach (['foreign_keys' => 1, 'ignore_check_constraints' => 0, 'recursive_triggers' => 0, 'synchronous' => 2, 'busy_timeout' => 0] as $pragma => $expected) {
            if ($pdo->query('PRAGMA '.$pragma)->fetchColumn() !== $expected) {
                throw new LogicException('Unexpected SQLite controls.');
            }
        }
        if ($pdo->query('PRAGMA encoding')->fetchColumn() !== 'UTF-8' || $pdo->query('PRAGMA journal_mode')->fetchColumn() !== 'delete' || (! $initializing && $pdo->query('PRAGMA user_version')->fetchColumn() !== 222)) {
            throw new LogicException('Unsupported lab configuration.');
        }
        foreach ($pdo->query('PRAGMA database_list')->fetchAll() as $entry) {
            if ($entry['name'] === 'main' ? $entry['file'] !== $this->ticket['path'] : $entry['name'] !== 'temp') {
                throw new LogicException('Unknown attached database.');
            }
        }
        if ($pdo->query('SELECT count(*) FROM temp.sqlite_schema')->fetchColumn() !== 0) {
            throw new LogicException('Temporary objects are not part of this lab.');
        }
    }

    public function owns(PDO $pdo): bool
    {
        return isset($this->connections[$pdo]);
    }

    public function invalidate(PDO $pdo): void
    {
        unset($this->connections[$pdo]);
    }

    public function busyEvents(): array
    {
        return $this->busy;
    }

    public function connectionBusyEvents(PDO $pdo): array
    {
        if (! $this->owns($pdo)) {
            throw new LogicException('Foreign or invalidated connection.');
        }

        return $this->connections[$pdo];
    }

    public function store(?PDO $pdo = null, int $attempts = 3, ?Closure $onBusy = null, ?Closure $afterCommit = null, ?Closure $beforeCommit = null): SqliteHoldStore
    {
        return SqliteHoldStore::owned($this, $pdo ?? $this->connect($attempts, $onBusy), $attempts, $onBusy, $afterCommit, $beforeCommit);
    }
}
