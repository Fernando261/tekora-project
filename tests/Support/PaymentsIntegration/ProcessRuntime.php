<?php

declare(strict_types=1);

namespace Tests\Support\PaymentsIntegration;

/** Linux lab attestation, before opening SQLite and after closing owned connections. */
final class ProcessRuntime
{
    public static function sqliteDescriptors(): array
    {
        if (PHP_OS_FAMILY !== 'Linux' || ! is_readable('/proc/self/fd')) {
            throw new \LogicException('Worker descriptor inspection requires Linux procfs');
        }
        $descriptors = scandir('/proc/self/fd');
        if ($descriptors === false) {
            throw new \LogicException('Worker descriptors unavailable');
        }
        $sqlite = [];
        foreach ($descriptors as $fd) {
            if (! ctype_digit($fd)) {
                continue;
            }
            $path = @readlink('/proc/self/fd/'.$fd);
            // Lab files have fixed .sqlite names; also reject common SQLite sidecars.
            // This does not certify arbitrary extensionless databases outside this lab.
            if ($path !== false && preg_match('/\.(?:sqlite3?|db)(?:-(?:journal|wal|shm))?(?: \(deleted\))?\z/', $path)) {
                $sqlite[] = (int) $fd;
            }
        }

        return $sqlite;
    }

    public static function assertNoSqliteDescriptors(): void
    {
        if (self::sqliteDescriptors() !== []) {
            throw new \LogicException('SQLite descriptors crossed the worker boundary');
        }
    }

    public static function assertExecSafeDescriptors(): void
    {
        foreach (self::sqliteDescriptors() as $fd) {
            $info = file_get_contents('/proc/self/fdinfo/'.$fd);
            if ($info === false || ! preg_match('/^flags:\s+([0-7]+)$/m', $info, $matches) || (octdec($matches[1]) & 02000000) === 0) {
                throw new \LogicException('SQLite descriptor must be close-on-exec before starting a worker');
            }
        }
    }
}
