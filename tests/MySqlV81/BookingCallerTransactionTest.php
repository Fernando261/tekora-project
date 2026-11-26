<?php

namespace Tests\MySqlV81;

use App\Exceptions\ReservationStorageUncertain;
use App\Services\ReservationTransactions;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Support\Facades\DB;
use LogicException;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

final class BookingCallerTransactionTest extends LabTestCase
{
    public static function savepointFailures(): iterable
    {
        foreach (['REPEATABLE READ', 'READ COMMITTED'] as $isolation) {
            foreach ([1, 2, 3] as $level) {
                foreach (['rollback-to', 'release-before', 'release-after'] as $fault) {
                    yield "$isolation level $level $fault" => [$isolation, $level, $fault];
                }
            }
        }
    }

    private function sessions(string $isolation = 'REPEATABLE READ'): array
    {
        $connection = DB::connection();
        $observer = $connection->getPdo();
        $config = $connection->getConfig();
        $pdo = new BookingSavepointFaultPdo('mysql:unix_socket='.$config['unix_socket'].';dbname='.$config['database'],
            $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec("SET SESSION time_zone='+00:00'");
        $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL '.$isolation);
        $watermark = (int) $connection->table('reservation_hold_clock')->where('id', 1)->value('high_water_us');
        $pdo->exec('SET @tekora_v81_booking_us='.max($watermark + 1, time() * 1000000));
        $connection->setPdo($pdo);
        $this->assertNotSame($observer->query('SELECT CONNECTION_ID()')->fetchColumn(), $pdo->query('SELECT CONNECTION_ID()')->fetchColumn());
        $this->assertSame(str_replace(' ', '-', $isolation), $pdo->query('SELECT @@transaction_isolation')->fetchColumn());

        return [$pdo, $observer];
    }

    private function saw(PDO $observer, string $prefix): array
    {
        $statement = $observer->prepare('SELECT `key` FROM cache WHERE `key` LIKE ? ORDER BY `key`');
        $statement->execute([$prefix.'%']);

        return $statement->fetchAll(PDO::FETCH_COLUMN);
    }

    private function write(string $key): void
    {
        DB::table('cache')->insert(['key' => $key, 'value' => 'synthetic transaction fixture', 'expiration' => 0]);
    }

    private function uncertain(callable $operation): ReservationStorageUncertain
    {
        try {
            $operation();
            $this->fail('The uncertain unit reported success.');
        } catch (ReservationStorageUncertain $error) {
            return $error;
        }
    }

    private function inject(string $fault, BookingSavepointFaultPdo $pdo, string $key, int &$calls): ReservationStorageUncertain
    {
        $pdo->savepointFault = $fault;

        return $this->uncertain(function () use ($key, $fault, &$calls) {
            app(ReservationTransactions::class)->run(function () use ($key, $fault, &$calls) {
                $calls++;
                $this->write($key);
                if ($fault === 'rollback-to') {
                    throw new RuntimeException('injected primary');
                }
            });
        });
    }

    #[DataProvider('savepointFailures')]
    public function test_captured_savepoint_failure_cannot_commit_at_any_laravel_level(string $isolation, int $depth, string $fault): void
    {
        [$pdo, $observer] = $this->sessions($isolation);
        $prefix = 'fix1-'.strtolower(str_replace(' ', '', $isolation)).'-'.$depth.'-'.$fault.'-';
        $original = null;
        $calls = $entries = $commits = 0;

        $frame = function (int $level) use (&$frame, &$original, &$entries, &$calls, &$commits, $pdo, $observer, $depth, $fault, $prefix) {
            return DB::transaction(function () use (&$frame, &$original, &$entries, &$calls, &$commits, $pdo, $observer, $depth, $fault, $prefix, $level) {
                $entries++;
                $this->assertSame($level, DB::transactionLevel());
                $this->write($prefix.'outer-'.$level);
                DB::afterCommit(function () use (&$commits) {
                    $commits++;
                });
                if ($level < $depth) {
                    // Even if every enclosing caller catches the error, none may finish successfully.
                    $failure = $this->uncertain(fn () => $frame($level + 1));
                    $this->assertSame($original, $failure);
                    $this->assertSame($level, DB::transactionLevel());
                    $this->assertTrue($pdo->inTransaction());
                } else {
                    $original = $this->inject($fault, $pdo, $prefix.'inner', $calls);
                    $this->assertSame($fault === 'rollback-to' ? 'injected primary' : 'injected '.$fault, $original->primaryError->getMessage());
                    if ($fault === 'rollback-to') {
                        $this->assertSame('injected rollback-to before send', $original->cleanupError->getMessage());
                    } elseif ($fault === 'release-after') {
                        $this->assertInstanceOf(PDOException::class, $original->cleanupError);
                        $this->assertSame(1305, $original->cleanupError->errorInfo[1]);
                    } else {
                        $this->assertNull($original->cleanupError);
                    }
                    $this->assertSame($level, DB::transactionLevel());
                    $this->assertTrue($pdo->inTransaction());
                    $this->assertSame($original, $this->uncertain(fn () => DB::commit()));
                    $this->assertSame($original, $this->uncertain(fn () => DB::getPdo()));
                    $this->assertSame($original, $this->uncertain(fn () => DB::beginTransaction()));
                    $this->assertSame($original, $this->uncertain(fn () => DB::transaction(fn () => $this->fail('Retried poisoned frame.'), 3)));
                    $this->assertSame($original, $this->uncertain(fn () => DB::afterCommit(fn () => $this->fail('Registered after uncertainty.'))));
                    if ($level > 1) {
                        try {
                            DB::rollBack();
                            $this->fail('Partial rollback accepted.');
                        } catch (LogicException $error) {
                            $this->assertStringContainsString('transacción completa', $error->getMessage());
                        }
                    }
                }
                $this->assertSame([], $this->saw($observer, $prefix));
                $this->assertSame(0, $commits);

                return 'attempted success';
            }, 3);
        };

        $failure = $this->uncertain(fn () => $frame(1));
        $this->assertSame($original, $failure);
        $this->assertFalse($pdo->inTransaction());
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame([], $this->saw($observer, $prefix));
        $this->assertSame($depth, $entries);
        $this->assertSame(1, $calls);
        $this->assertSame(0, $commits);

        $this->assertSame('recovered', DB::transaction(function () use ($prefix, &$commits) {
            app(ReservationTransactions::class)->run(fn () => $this->write($prefix.'recovered'));
            DB::afterCommit(function () use (&$commits) {
                $commits++;
            });

            return 'recovered';
        }));
        $this->assertSame(1, $commits);
        $this->assertSame([$prefix.'recovered'], $this->saw($observer, $prefix));
    }

    public function test_nested_transaction_leaves_manually_opened_root_in_callers_custody(): void
    {
        [$pdo, $observer] = $this->sessions();
        DB::beginTransaction();
        $calls = $commits = 0;
        try {
            $this->write('fix1-manual-outer');
            DB::afterCommit(function () use (&$commits) {
                $commits++;
            });
            $this->uncertain(function () use ($pdo, &$calls) {
                DB::transaction(function () use ($pdo, &$calls) {
                    $this->inject('release-after', $pdo, 'fix1-manual-inner', $calls);

                    return 'attempted success';
                }, 3);
            });
            $this->assertTrue($pdo->inTransaction());
            $this->assertSame(1, DB::transactionLevel());
            $this->assertSame([], $this->saw($observer, 'fix1-manual-'));
        } finally {
            DB::rollBack(0);
        }
        $this->assertSame(1, $calls);
        $this->assertSame(0, $commits);
        $this->assertSame([], $this->saw($observer, 'fix1-manual-'));
        $this->assertFalse($pdo->inTransaction());
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_failed_full_rollback_keeps_session_blocked_until_owner_proves_rollback(): void
    {
        [$pdo, $observer] = $this->sessions();
        $calls = $commits = 0;
        $pdo->fullRollbackFault = true;
        try {
            $failure = $this->uncertain(function () use ($pdo, &$calls, &$commits) {
                DB::transaction(function () use ($pdo, &$calls, &$commits) {
                    $this->write('fix1-root-failure-outer');
                    DB::afterCommit(function () use (&$commits) {
                        $commits++;
                    });
                    $this->inject('rollback-to', $pdo, 'fix1-root-failure-inner', $calls);
                }, 3);
            });
            $this->assertInstanceOf(ReservationStorageUncertain::class, $failure->primaryError);
            $this->assertSame('injected primary', $failure->primaryError->primaryError->getMessage());
            $this->assertSame('injected full rollback before send', $failure->cleanupError->getMessage());
            $this->assertTrue($pdo->inTransaction());
            $this->assertSame(1, DB::transactionLevel());
            $this->assertSame([], $this->saw($observer, 'fix1-root-failure-'));
            $this->assertSame($failure->primaryError, $this->uncertain(fn () => DB::commit()));
            $this->assertSame($failure->primaryError, $this->uncertain(fn () => DB::purge()));
            $this->assertSame($pdo, DB::connection()->getRawPdo());
        } finally {
            $pdo->fullRollbackFault = false;
            DB::rollBack(0);
        }
        $this->assertSame(1, $calls);
        $this->assertSame(0, $commits);
        $this->assertSame([], $this->saw($observer, 'fix1-root-failure-'));
        $this->assertSame('reusable', DB::transaction(fn () => 'reusable'));
    }

    public function test_committing_event_cannot_poison_then_bypass_pdo_guard(): void
    {
        [$pdo, $observer] = $this->sessions();
        $calls = $commits = 0;
        $eventFailure = null;
        $this->app['events']->listen(TransactionCommitting::class, function (TransactionCommitting $event) use ($pdo, &$calls, &$eventFailure) {
            if ($event->connection === DB::connection()) {
                $eventFailure = $this->inject('rollback-to', $pdo, 'fix1-event-inner', $calls);
            }
        });

        $failure = $this->uncertain(function () use (&$commits) {
            DB::transaction(function () use (&$commits) {
                $this->write('fix1-event-outer');
                DB::afterCommit(function () use (&$commits) {
                    $commits++;
                });
                // A completed nested Laravel frame has callbacks staged for the root.
                DB::transaction(function () use (&$commits) {
                    $this->write('fix1-event-staged');
                    DB::afterCommit(function () use (&$commits) {
                        $commits++;
                    });
                });
            }, 3);
        });
        $this->assertSame($eventFailure, $failure);
        $this->assertSame(1, $calls);
        $this->assertSame(0, $commits);
        $this->assertFalse($pdo->inTransaction());
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame([], $this->saw($observer, 'fix1-event-'));
    }

    public function test_healthy_laravel_commit_and_rollback_keep_their_normal_lifecycle(): void
    {
        [$pdo, $observer] = $this->sessions('READ COMMITTED');
        $commits = 0;
        $this->assertSame('ok', DB::transaction(function () use ($observer, &$commits) {
            $this->write('fix1-healthy-outer');
            DB::transaction(function () use (&$commits) {
                app(ReservationTransactions::class)->run(fn () => $this->write('fix1-healthy-inner'));
                DB::afterCommit(function () use (&$commits) {
                    $commits++;
                });
            });
            $this->assertSame([], $this->saw($observer, 'fix1-healthy-'));
            $this->assertSame(0, $commits);

            return 'ok';
        }));
        $this->assertSame(1, $commits);
        $this->assertSame(['fix1-healthy-inner', 'fix1-healthy-outer'], $this->saw($observer, 'fix1-healthy-'));

        DB::beginTransaction();
        app(ReservationTransactions::class)->run(fn () => $this->write('fix1-healthy-ordinary-rollback'));
        DB::afterCommit(function () use (&$commits) {
            $commits++;
        });
        DB::rollBack();
        $this->assertSame(1, $commits);
        $this->assertSame(['fix1-healthy-inner', 'fix1-healthy-outer'], $this->saw($observer, 'fix1-healthy-'));
        $this->assertFalse($pdo->inTransaction());
        $this->assertSame(0, DB::transactionLevel());
    }
}

final class BookingSavepointFaultPdo extends PDO
{
    public string $savepointFault = '';

    public bool $fullRollbackFault = false;

    public function exec(string $statement): int|false
    {
        if ($this->savepointFault === 'rollback-to' && str_starts_with($statement, 'ROLLBACK TO SAVEPOINT tekora_b2_')) {
            $this->savepointFault = '';
            throw new RuntimeException('injected rollback-to before send');
        }
        if (in_array($this->savepointFault, ['release-before', 'release-after'], true) && str_starts_with($statement, 'RELEASE SAVEPOINT tekora_b2_')) {
            $fault = $this->savepointFault;
            $this->savepointFault = '';
            if ($fault === 'release-after') {
                parent::exec($statement);
            }
            throw new RuntimeException('injected '.$fault);
        }

        return parent::exec($statement);
    }

    public function rollBack(): bool
    {
        if ($this->fullRollbackFault) {
            throw new RuntimeException('injected full rollback before send');
        }

        return parent::rollBack();
    }
}
