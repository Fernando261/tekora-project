<?php

declare(strict_types=1);

namespace Tests\Support\PricingSqlite;

use Closure;
use LogicException;
use PDO;
use Throwable;
use WeakMap;

/** Capability for a database created by this test run. No existing-path/DSN constructor. */
final class SyntheticPricingDatabase
{
    private WeakMap $connections;

    private function __construct(private readonly string $path, private readonly array $identity)
    {
        $this->connections = new WeakMap;
    }

    public static function create(): self
    {
        $parent = getenv('TEKORA_B221_LAB_PARENT') ?: sys_get_temp_dir();
        $directory = $parent.'/tekora-b221-'.bin2hex(random_bytes(16));
        if (! mkdir($directory, 0700)) {
            throw new LogicException('Cannot create a fresh synthetic directory.');
        }
        $path = $directory.'/pricing.sqlite';
        $file = fopen($path, 'x+b');
        if ($file === false) {
            throw new LogicException('Cannot exclusively create a synthetic database.');
        }
        if (! chmod($path, 0600)) {
            fclose($file);
            throw new LogicException('Cannot restrict the newly created synthetic file.');
        }
        $identity = fstat($file);
        fclose($file);
        $database = new self($path, ['dev' => $identity['dev'], 'ino' => $identity['ino']]);
        $pdo = $database->connect();
        if (PHP_INT_SIZE !== 8 || version_compare($pdo->query('SELECT sqlite_version()')->fetchColumn(), '3.38.0', '<')) {
            throw new LogicException('The prototype requires 64-bit PHP and SQLite >= 3.38.');
        }
        $pdo->exec('PRAGMA journal_mode = DELETE');
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $pdo->exec(file_get_contents(__DIR__.'/schema.sql'));
            $pdo->exec('PRAGMA user_version = 221');
            $pdo->exec('COMMIT');
        } catch (Throwable $error) {
            $pdo->exec('ROLLBACK');
            throw $error;
        }

        return $database;
    }

    public function connect(): PDO
    {
        clearstatcache(true, $this->path);
        $identity = lstat($this->path);
        if (is_link($this->path) || $identity === false || ($identity['mode'] & 0170000) !== 0100000
            || $identity['dev'] !== $this->identity['dev'] || $identity['ino'] !== $this->identity['ino']) {
            throw new LogicException('The synthetic file identity is no longer valid.');
        }
        $pdo = new PDO('sqlite:'.$this->path, options: [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::ATTR_PERSISTENT => false,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 0');
        $pdo->exec('PRAGMA synchronous = FULL');
        if ($pdo->query('PRAGMA foreign_keys')->fetchColumn() !== 1) {
            throw new LogicException('Foreign keys must be enabled.');
        }
        $this->connections[$pdo] = true;

        return $pdo;
    }

    public function owns(PDO $pdo): bool
    {
        return isset($this->connections[$pdo]);
    }

    public function invalidate(PDO $pdo): void
    {
        unset($this->connections[$pdo]);
    }

    public function store(?PDO $pdo = null, int $attempts = 3, ?Closure $onBusy = null): SqlitePricingStore
    {
        return SqlitePricingStore::owned($this, $pdo ?? $this->connect(), $attempts, $onBusy);
    }
}
