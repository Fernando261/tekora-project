<?php

declare(strict_types=1);

namespace Tests\PaymentsIntegrationPrototype;

use App\Scheduling\Holds\HoldActor;
use Tests\Support\PaymentsIntegration\IntegrationTestCase;
use Tests\Support\PaymentsIntegration\OwnedIntegrationLab;
use Tests\Support\PaymentsIntegration\ReferenceLoader;
use Tests\Support\PaymentsIntegration\ScenarioFixture;
use Tests\Support\PaymentsIntegration\SharedSqliteUnitOfWork;
use Tests\Support\PaymentsIntegration\WriteSession;

#[\PHPUnit\Framework\Attributes\Group('b231-A-contracts')]
final class SharedSessionTest extends IntegrationTestCase
{
    public function test_t01_exact_classes_and_absent_reference_fail_closed(): void
    {
        $path = (new \ReflectionClass(\App\Payments\CommercialAmount::class))->getFileName();
        self::assertStringContainsString('/pr32/', $path);
        self::assertStringContainsString('/pr33/', (new \ReflectionClass(\App\Scheduling\Holds\SlotHold::class))->getFileName());
        $this->rejects(fn () => ReferenceLoader::register('/definitely-absent'), \Throwable::class);
    }

    public function test_t02_new_files_and_inodes_are_not_adopted(): void
    {
        [$permit,$token] = $this->lab->capability();
        $this->rejects(fn () => OwnedIntegrationLab::resume($permit, str_repeat('0', 64)));
        $resumed = OwnedIntegrationLab::resume($permit, $token);
        self::assertSame(231, $resumed->connect()->query('PRAGMA user_version')->fetchColumn());
        $this->rejects(fn () => OwnedIntegrationLab::resume(dirname($permit).'/missing', $token));
    }

    public function test_t02_file_substitution_stops_without_cleanup(): void
    {
        $this->checkIntegrity = false;
        [$p] = $this->lab->capability();
        $db = dirname($p).'/fixture.sqlite';
        $this->unit->close();
        rename($db, $db.'.retained');
        $handle = fopen($db, 'xb');
        fclose($handle);
        chmod($db, 0600);
        $this->rejects(fn () => $this->lab->connect());
        self::assertFileExists($db.'.retained');
    }

    public function test_t03_t08_all_participants_use_same_pdo_and_epoch_with_visibility(): void
    {
        $this->proposal();
        $seen = null;
        $f = new \Tests\Support\PaymentsIntegration\ScenarioFixture($this->unit, function ($stage) use (&$seen): void {
            if ($stage === 'head') {
                $seen = $this->rowCount('holds');
                self::assertSame(0, $seen);
                self::assertSame(0, $this->rowCount('claims'));
            }
        });
        $result = $f->accept(101, 1, 1, $this->key());
        self::assertSame('held', $result->historical['code']);
        self::assertSame(0, $seen);
        self::assertSame(1, $this->rowCount('holds'));
        self::assertSame(1, $this->rowCount('claims'));
        self::assertSame(1, $this->rowCount('activations'));
        self::assertCount(1, array_unique(array_column($this->unit->diagnostics, 'connection')));
        $epoch = max(array_column($this->unit->diagnostics, 'epoch'));
        self::assertGreaterThan(8, count(array_filter($this->unit->diagnostics, fn ($d) => $d['epoch'] === $epoch)));
    }

    public function test_t04_t05_foreign_session_and_reentry_are_rejected(): void
    {
        $other = new SharedSqliteUnitOfWork(OwnedIntegrationLab::create(), fn () => $this->clock->now());
        $c = ScenarioFixture::intent('quote', HoldActor::client(201), $this->key(), [11]);
        $this->unit->run($c, function (WriteSession $s) use ($other, $c): array {
            $this->rejects(fn () => $other->assertSession($s));
            $this->rejects(fn () => $other->run($c, fn () => ['code' => 'bad']));
            $this->rejects(fn () => $this->unit->run($c, fn () => ['code' => 'bad']));

            return ['code' => 'rejected', 'outcome' => 'rejected'];
        });
        $other->close();
    }

    public function test_t06_lease_revoked_after_commit_and_rollback(): void
    {
        $saved = null;
        $this->proposal();
        $c = ScenarioFixture::intent('probe', HoldActor::system(), $this->key(), []);
        $this->unit->run($c, function ($s) use (&$saved): array {
            $saved = $s;

            return ['code' => 'observed'];
        });
        $this->rejects(fn () => $saved->pricing->latestPrice(1));
        $box = (object) ['session' => null];
        $this->rejects(fn () => $this->unit->run(ScenarioFixture::intent('probe', HoldActor::system(), $this->key(), []), function ($s) use ($box): array {
            $box->session = $s;
            throw new \RuntimeException('rollback');
        }), \RuntimeException::class);
        $this->rejects(fn () => $box->session->proposals->context(1));
        $this->unit->close();
        $this->rejects(fn () => $saved->holds->due());
    }

    public function test_t07_fork_cannot_use_inherited_session(): void
    {
        if (! function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl unavailable');
        }
        $c = ScenarioFixture::intent('probe', HoldActor::system(), $this->key(), []);
        $this->unit->run($c, function ($s): array {
            $pid = pcntl_fork();
            if ($pid === 0) {
                try {
                    $s->pricing->latestPrice(1);
                    exit(4);
                } catch (\LogicException) {
                    exit(0);
                }
            }
            pcntl_waitpid($pid, $status);
            self::assertSame(0, pcntl_wexitstatus($status));

            return ['code' => 'pid_guard'];
        });
    }

    public function test_unknown_temp_objects_and_attached_databases_are_rejected(): void
    {
        $p = $this->lab->connect();
        $p->exec('CREATE TEMP TABLE sqliteX_probe(a)');
        $this->rejects(fn () => $this->lab->admit($p));
        $p = $this->lab->connect();
        $p->exec("ATTACH ':memory:' AS alien");
        $this->rejects(fn () => $this->lab->admit($p));
    }

    public function test_session_tokens_cannot_be_serialized_or_used_by_a_different_owner(): void
    {
        $this->rejects(fn () => serialize(new \Tests\Support\PaymentsIntegration\SessionLease(getmypid(), 1)));
        $methodNames = array_map(fn ($m) => $m->getName(), (new \ReflectionClass(WriteSession::class))->getMethods(\ReflectionMethod::IS_PUBLIC));
        foreach (['exec', 'begin', 'commit', 'rollback', 'atomic', 'pdo'] as $name) {
            self::assertNotContains($name, $methodNames);
        }
    }

    public function test_close_during_active_work_rolls_back_and_revokes_ports(): void
    {
        $box = (object) ['session' => null];
        $intent = ScenarioFixture::intent('probe', HoldActor::system(), $this->key(), []);
        $this->rejects(fn () => $this->unit->run($intent, function ($s) use ($box): array {
            $box->session = $s;
            $this->unit->close();

            return ['code' => 'closed'];
        }));
        $this->rejects(fn () => $box->session->pricing->latestPrice(1));
        self::assertSame(0, $this->rowCount('commands'));
    }
}
