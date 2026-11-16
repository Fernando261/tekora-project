<?php

declare(strict_types=1);

namespace Tests\Support\BookingOwnershipLab;

use PDO;
use PDOException;

/** Privileged test harness. No caller-provided SQL, framework hooks or payload. */
final class FaultPlan
{
    public array $hits = [];

    private ?PDO $external = null;

    private bool $callerClosed = false;

    private array $releaseBarriers = [];

    private ?LabFixture $holdAfterA = null;

    private ?LabFixture $notifyBusy = null;

    private bool $busyNotified = false;

    public function __construct(private readonly array $faults = [], public readonly ?string $foreignMode = null, public readonly bool $allowBrokenStatement = false, public readonly ?string $profileProbe = null, public readonly ?string $coordinationFault = null)
    {
        foreach ($faults as $point => $kind) {
            if (! FaultPoint::tryFrom($point) || ! in_array($kind, ['throw', 'busy_once', 'busy_always', 'false', 'identity', 'pid', 'generation', 'delay', 'locked', 'ioerr', 'readonly', 'extended_busy'], true)) {
                throw new LabFailure('UnsupportedFaultPoint', 'PREFLIGHT');
            }
        }
        if ($foreignMode !== null && ! in_array($foreignMode, ['pdo', 'begin', 'immediate', 'exclusive', 'savepoint', 'level'], true)) {
            throw new LabFailure('UnsupportedFaultPoint', 'PREFLIGHT');
        }
        if ($profileProbe !== null && ! in_array($profileProbe, ['silent', 'warning', 'persistent', 'lazy', 'replica', 'raw', 'read_pdo', 'manager', 'event', 'reconnector', 'nested', 'after_commit', 'before_execute'], true)) {
            throw new LabFailure('UnsupportedFaultPoint', 'PREFLIGHT');
        }
        if ($coordinationFault !== null && ! in_array($coordinationFault, ['missing', 'epoch', 'version'], true)) {
            throw new LabFailure('UnsupportedFaultPoint', 'PREFLIGHT');
        }
    }

    public function hit(FaultPoint $point): bool
    {
        $count = $this->hits[$point->value] = ($this->hits[$point->value] ?? 0) + 1;
        if (isset($this->releaseBarriers[$point->value]) && $count === 2) {
            $fixture = $this->releaseBarriers[$point->value];
            self::signal($fixture, 'release');
            self::await($fixture, 'released');
        }
        if ($point === FaultPoint::AFTER_A && $this->holdAfterA !== null) {
            self::signal($this->holdAfterA, 'a-left');
            self::await($this->holdAfterA, 'tried-right');
        }
        $kind = $this->faults[$point->value] ?? null;
        if ($kind === 'false') {
            return false;
        }
        if ($kind === 'delay') {
            usleep(300_000); // Injected delayed acknowledgement, not business retry.
        }
        if ($kind === 'busy_always' || ($kind === 'busy_once' && $count === 1)) {
            throw self::diagnostic('HY000', 5, 'database is locked');
        }
        if ($kind === 'throw') {
            throw new LabFailure('InjectedFailure', $point->value);
        }
        if (in_array($kind, ['locked', 'ioerr', 'readonly', 'extended_busy'], true)) {
            throw self::diagnostic('HY000', ['locked' => 6, 'ioerr' => 10, 'readonly' => 8, 'extended_busy' => 261][$kind], 'Injected driver failure');
        }

        return true;
    }

    public static function diagnostic(string $state, mixed $code, string $message): PDOException
    {
        $error = new PDOException('Synthetic driver diagnostic');
        $error->errorInfo = [$state, $code, $message];

        return $error;
    }

    public function identityMutation(): ?string
    {
        $kind = $this->faults[FaultPoint::BEFORE_DATA->value] ?? null;

        return in_array($kind, ['identity', 'pid', 'generation'], true) ? $kind : null;
    }

    public function mutateCoordination(PDO $pdo, bool $afterRead): void
    {
        if (! $afterRead && $this->coordinationFault === 'missing') {
            $pdo->exec('DELETE FROM coordination WHERE id=1');
        } elseif (! $afterRead && $this->coordinationFault === 'epoch') {
            $pdo->exec("UPDATE coordination SET epoch='injected-invalid-epoch' WHERE id=1");
        } elseif ($afterRead && $this->coordinationFault === 'version') {
            $pdo->exec('UPDATE coordination SET admission_version=admission_version+1 WHERE id=1');
        }
    }

    public function attachForeign(PDO $pdo): void
    {
        if ($this->foreignMode === null) {
            return;
        }
        $this->external = $pdo;
        match ($this->foreignMode) {
            'pdo', 'level' => $pdo->beginTransaction(),
            'begin' => $pdo->exec('BEGIN'),
            'immediate' => $pdo->exec('BEGIN IMMEDIATE'),
            'exclusive' => $pdo->exec('BEGIN EXCLUSIVE'),
            'savepoint' => $pdo->exec('SAVEPOINT caller_outer'),
        };
        $pdo->exec('UPDATE coordination SET admission_version=7');
    }

    public function callerPdo(): PDO
    {
        return $this->external ?? throw new LabFailure('NoForeignHarness', 'CALLER');
    }

    /** Called by the legitimate negative-test caller, never by the adapter. */
    public function callerRollback(): void
    {
        $pdo = $this->callerPdo();
        if (in_array($this->foreignMode, ['pdo', 'level'], true)) {
            if ($pdo->rollBack() !== true) {
                throw new LabFailure('CallerCloseUnknown', 'CALLER');
            }
        } else {
            $pdo->exec('ROLLBACK');
        }
        $this->callerClosed = true;
        $this->external = null;
    }

    public function callerCloseProven(): bool
    {
        return $this->callerClosed;
    }

    public function releaseOnRetry(LabFixture $fixture, FaultPoint $point): self
    {
        if (! in_array($point, [FaultPoint::BEFORE_NATIVE_BEGIN, FaultPoint::BEFORE_NATIVE_COMMIT], true)) {
            throw new LabFailure('UnsupportedFaultPoint', 'BARRIER');
        }
        $this->releaseBarriers[$point->value] = $fixture;

        return $this;
    }

    public function concurrencyBarrier(LabFixture $fixture, string $worker): self
    {
        if ($worker === 'left') {
            $this->holdAfterA = $fixture;
        } elseif ($worker === 'right') {
            $this->notifyBusy = $fixture;
        } else {
            throw new LabFailure('UnsupportedFaultPoint', 'BARRIER');
        }

        return $this;
    }

    public function admissionBusyClosed(): void
    {
        if ($this->notifyBusy !== null && ! $this->busyNotified) {
            self::signal($this->notifyBusy, 'tried-right');
            $this->busyNotified = true;
            self::await($this->notifyBusy, 'committed-left');
        }
    }

    public static function signal(LabFixture $fixture, string $name): void
    {
        $path = self::signalPath($fixture, $name);
        if (file_exists($path)) {
            throw new LabFailure('EvidenceFailure', 'BARRIER');
        }
        $pending = $path.'.pending';
        $file = fopen($pending, 'xb');
        if ($file === false) {
            throw new LabFailure('EvidenceFailure', 'BARRIER');
        }
        chmod($pending, 0600);
        fwrite($file, json_encode(['pid' => getmypid(), 'time_ns' => hrtime(true)], JSON_THROW_ON_ERROR));
        fclose($file);
        if (! rename($pending, $path)) {
            throw new LabFailure('EvidenceFailure', 'BARRIER');
        }
    }

    public static function await(LabFixture $fixture, string $name): void
    {
        $path = self::signalPath($fixture, $name);
        $deadline = hrtime(true) + 3_000_000_000;
        do {
            if (is_file($path) && ! is_link($path)) {
                $message = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
                if (isset($message['pid'], $message['time_ns'])) {
                    return;
                }
            }
            usleep(1000);
        } while (hrtime(true) < $deadline);
        throw new LabFailure('BarrierTimeout', 'BARRIER');
    }

    private static function signalPath(LabFixture $fixture, string $name): string
    {
        $fixture->verifyPaths();
        if (! in_array($name, ['release', 'released', 'ready-holder', 'ready-left', 'ready-right', 'go-left', 'go-right', 'a-left', 'tried-right', 'committed-left'], true)) {
            throw new LabFailure('UnsupportedFaultPoint', 'BARRIER');
        }

        return $fixture->directory.'/'.$name.'.signal';
    }

    public static function assertNoInheritedHandles(): array
    {
        if (PHP_OS_FAMILY !== 'Linux' || ! is_dir('/proc/self/fd')) {
            throw new LabFailure('FdPreflightUnavailable', 'PROCESS');
        }
        $fds = [];
        $entryScripts = [realpath(dirname(__DIR__, 3).'/vendor/bin/phpunit'), realpath(__DIR__.'/worker.php')];
        foreach (scandir('/proc/self/fd') as $fd) {
            if (! ctype_digit($fd)) {
                continue;
            }
            $target = @readlink('/proc/self/fd/'.$fd);
            if ($target === false) {
                continue; // The descriptor used by scandir is already closed.
            }
            // PHP CLI's entry stub may remain inherited when PHPUnit sends the child job on stdin.
            // Only these two immutable, explicitly named runtime stubs are admitted, never *.php.
            $entry = in_array($target, $entryScripts, true)
                && preg_match('/\A(?:#![^\n]*\n)?<\?php\b/', file_get_contents($target, false, null, 0, 256)) === 1;
            // PHPUnit's declared JUnit sink is the only additional regular-file transport.
            $junit = dirname(LabFixture::runRoot()).'/junit.xml';
            $info = file_get_contents('/proc/self/fdinfo/'.$fd);
            preg_match('/^flags:\s+([0-7]+)/m', $info, $flags);
            $junitSink = $target === $junit && ! is_link($junit) && is_file($junit)
                && lstat($junit)['nlink'] === 1 && isset($flags[1]) && (octdec($flags[1]) & 3) === 1;
            if ((int) $fd > 2 && ! $entry && ! $junitSink && ! preg_match('/\Apipe:\[\d+\]\z/', $target) && ! in_array($target, ['/dev/null', '/dev/urandom'], true)) {
                file_put_contents(LabFixture::runRoot().'/fd-rejection-'.getmypid().'.json', json_encode(['pid' => getmypid(), 'fd' => (int) $fd, 'target' => $target], JSON_THROW_ON_ERROR));
                throw new LabFailure('InheritedHandleRejected', 'PROCESS');
            }
            $fds[$fd] = $target;
        }

        return $fds;
    }
}
