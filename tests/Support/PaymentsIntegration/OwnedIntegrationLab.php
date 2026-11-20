<?php

declare(strict_types=1);

namespace Tests\Support\PaymentsIntegration;

use LogicException;
use PDO;

/** New resources only. Permits stay private; no automatic deletion or adoption. */
final class OwnedIntegrationLab
{
    private readonly int $pid;

    private function __construct(private array $permit)
    {
        $this->pid = getmypid();
    }

    public static function create(?int $legacyStart = null): self
    {
        if (PHP_INT_SIZE !== 8 || ! function_exists('posix_geteuid')) {
            throw new LogicException('Linux POSIX x64 laboratory required');
        }
        $parent = getenv('TEKORA_B231_RUN_ROOT') ?: sys_get_temp_dir();
        if (is_link($parent) || realpath($parent) !== $parent || ! is_dir($parent)) {
            throw new LogicException('Canonical new run parent required');
        }
        $root = $parent.'/tekora-b231-'.bin2hex(random_bytes(16));
        if (! mkdir($root, 0700)) {
            throw new LogicException('Exclusive run root failed');
        }
        $path = $root.'/fixture.sqlite';
        $f = fopen($path, 'x+b');
        if ($f === false) {
            throw new LogicException('Exclusive SQLite creation failed');
        }
        chmod($path, 0600);
        fclose($f);
        $stat = lstat($path);
        $dir = lstat($root);
        $permit = ['root' => $root, 'path' => $path, 'dev' => $stat['dev'], 'ino' => $stat['ino'], 'dir_dev' => $dir['dev'], 'dir_ino' => $dir['ino'], 'uid' => posix_geteuid(), 'token' => bin2hex(random_bytes(32)), 'schema' => hash_file('sha256', __DIR__.'/fixture-schema.sql')];
        $lab = new self($permit);
        $p = $lab->raw();
        $p->exec(file_get_contents(__DIR__.'/fixture-schema.sql'));
        $p->exec('BEGIN IMMEDIATE');
        try {
            $p->exec('INSERT INTO b231_users VALUES(101),(102),(201),(202)');
            $p->exec('INSERT INTO b231_providers VALUES(11,101),(12,102)');
            $p->exec('INSERT INTO b231_services VALUES(1,11),(2,11),(3,12)');
            $p->exec("INSERT INTO b231_inquiries VALUES(11,1,11,201,'accepted'),(12,2,11,202,'accepted'),(13,3,12,201,'accepted'),(14,1,11,202,'accepted')");
            $p->exec('COMMIT');
        } catch (\Throwable $e) {
            $p->exec('ROLLBACK');
            throw $e;
        }
        if ($legacyStart !== null) {
            $lab->seedLegacy($p, $legacyStart);
        }
        $p->exec('INSERT INTO b231_fixture_seal VALUES(1)');
        $permit['catalog'] = $lab->catalog($p);
        $lab = new self($permit);
        $f = fopen($root.'/permit.json', 'xb');
        chmod($root.'/permit.json', 0600);
        fwrite($f, CommandEnvelope::json($permit));
        fclose($f);

        return $lab;
    }

    private function seedLegacy(PDO $p, int $start): void
    {
        // Only reachable while constructing a brand-new lab; no legacy-import API.
        $command = CommandEnvelope::uuid();
        $claim = CommandEnvelope::uuid();
        $at = 1794477600000000;
        $p->exec('BEGIN IMMEDIATE');
        try {
            $q = $p->prepare('INSERT INTO b231_proposals VALUES(900,1,11,1,11,101,201,?,?,?)');
            $q->execute([$start, $start + 3600000000, $command]);
            $q->closeCursor();
            $q = $p->prepare('INSERT INTO b231_legacy VALUES(900,?)');
            $q->execute([$claim]);
            $q->closeCursor();
            $q = $p->prepare("INSERT INTO b231_claims VALUES(?,900,11,1,11,101,201,?,?,'legacy',NULL,NULL,'reserved',NULL,'committed',1,?,?)");
            $q->execute([$claim, $start, $start + 3600000000, $at, $command]);
            $q->closeCursor();
            $q = $p->prepare("INSERT INTO b231_heads VALUES(900,'confirmed',1,?,'reserved',?,?)");
            $q->execute([$claim, $at, $command]);
            $q->closeCursor();
            $q = $p->prepare("INSERT INTO b231_commands VALUES(?,'legacy','system:system',?,?,'import_fixture','success','{\"code\":\"legacy_fixture\"}',?,'system',NULL)");
            $q->execute([$command, CommandEnvelope::uuid(), str_repeat('a', 64), $at]);
            $q->closeCursor();
            $p->exec('COMMIT');
        } catch (\Throwable $e) {
            $p->exec('ROLLBACK');
            throw $e;
        }
    }

    /** Same-run child only: caller must already possess this unlogged capability. */
    public static function resume(string $permitFile, string $token): self
    {
        if (is_link($permitFile) || ! is_file($permitFile) || realpath($permitFile) !== $permitFile) {
            throw new LogicException('Invalid permit file');
        }
        $s = lstat($permitFile);
        if ($s['uid'] !== posix_geteuid() || ($s['mode'] & 0777) !== 0600) {
            throw new LogicException('Private permit required');
        }
        $p = json_decode(file_get_contents($permitFile), true, flags: JSON_THROW_ON_ERROR);
        if (! hash_equals($p['token'], $token) || dirname($permitFile) !== $p['root'] || $p['schema'] !== hash_file('sha256', __DIR__.'/fixture-schema.sql')) {
            throw new LogicException('Wrong run permit');
        }
        $lab = new self($p);
        $lab->verifyFile();

        return $lab;
    }

    public function capability(): array
    {
        $this->assertProcess();

        return [$this->permit['root'].'/permit.json', $this->permit['token']];
    }

    private function assertProcess(): void
    {
        if ($this->pid !== getmypid()) {
            throw new LogicException('Lab belongs to another process; resume the permit in a fresh worker');
        }
    }

    public function verifyFile(): void
    {
        $this->assertProcess();
        foreach ([$this->permit['root'] => ['dir_dev', 'dir_ino', 0700], $this->permit['path'] => ['dev', 'ino', 0600]] as $path => $keys) {
            clearstatcache(true, $path);
            $s = lstat($path);
            if (! $s || is_link($path) || realpath($path) !== $path || $s['dev'] !== $this->permit[$keys[0]] || $s['ino'] !== $this->permit[$keys[1]] || $s['uid'] !== $this->permit['uid'] || ($s['mode'] & 0777) !== $keys[2] || (is_file($path) && $s['nlink'] !== 1)) {
                throw new LogicException('Synthetic resource identity changed');
            }
        }
        foreach (glob($this->permit['root'].'/*') as $path) {
            if (! in_array(basename($path), ['fixture.sqlite', 'fixture.sqlite-journal', 'permit.json'], true) || is_link($path)) {
                throw new LogicException('Unknown resource; retain and stop');
            }
            if (str_ends_with($path, '-journal')) {
                $s = lstat($path);
                if ($s['uid'] !== $this->permit['uid'] || ($s['mode'] & 0777) !== 0600 || $s['nlink'] !== 1) {
                    throw new LogicException('Unsafe sidecar');
                }
            }
        }
    }

    private function raw(): PDO
    {
        $this->verifyFile();
        $p = new PDO('sqlite:'.$this->permit['path'], null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_STRINGIFY_FETCHES => false, PDO::ATTR_TIMEOUT => 0]);
        if (version_compare($p->query('SELECT sqlite_version()')->fetchColumn(), '3.38.0', '<')) {
            throw new LogicException('Unsupported SQLite version');
        }
        $p->exec('PRAGMA foreign_keys=ON; PRAGMA ignore_check_constraints=OFF; PRAGMA recursive_triggers=OFF; PRAGMA busy_timeout=0; PRAGMA synchronous=FULL;');
        $this->verifyFile();

        return $p;
    }

    public function connect(?SqliteOutcomePolicy $policy = null): PDO
    {
        $policy ??= new SqliteOutcomePolicy;

        return $policy->perform('connect', function () {
            $p = $this->raw();
            $this->admit($p);

            return $p;
        });
    }

    private function catalog(PDO $p): string
    {
        return hash('sha256', CommandEnvelope::json($p->query("SELECT type,name,tbl_name,sql FROM main.sqlite_master WHERE substr(name,1,7)<>'sqlite_' ORDER BY type,name")->fetchAll()));
    }

    public function admit(PDO $p): void
    {
        $this->verifyFile();
        $list = $p->query('PRAGMA database_list')->fetchAll();
        if (count(array_filter($list, fn ($d) => $d['name'] !== 'temp')) !== 1 || $list[0]['name'] !== 'main' || $list[0]['file'] !== $this->permit['path'] || $p->inTransaction() || $p->query('SELECT count(*) FROM sqlite_temp_master')->fetchColumn() !== 0) {
            throw new LogicException('Unknown connection context');
        }
        foreach (['user_version' => 231, 'foreign_keys' => 1, 'ignore_check_constraints' => 0, 'recursive_triggers' => 0, 'busy_timeout' => 0, 'synchronous' => 2, 'journal_mode' => 'delete'] as $n => $v) {
            if ($p->query('PRAGMA '.$n)->fetchColumn() !== $v) {
                throw new LogicException('Unsupported SQLite profile: '.$n);
            }
        }
        if (isset($this->permit['catalog']) && $this->catalog($p) !== $this->permit['catalog']) {
            throw new LogicException('Schema catalog differs');
        }
    }
}
