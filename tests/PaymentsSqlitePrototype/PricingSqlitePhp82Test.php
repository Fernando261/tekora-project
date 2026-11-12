<?php

declare(strict_types=1);

namespace Tests\PaymentsSqlitePrototype;

use LogicException;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\PricingSqlite\PricingFixture;
use Tests\Support\PricingSqlite\PricingValues;
use Tests\Support\PricingSqlite\SqlitePricingBusy;

#[Group('pricing-sqlite-fix2')]
final class PricingSqlitePhp82Test extends TestCase
{
    use PricingFixture;

    public static function externalTransactions(): array
    {
        return [
            'manual deferred' => ['BEGIN'],
            'manual savepoint' => ['SAVEPOINT caller'],
            'PDO transaction' => [null],
        ];
    }

    #[DataProvider('externalTransactions')]
    public function test_external_transaction_is_rejected_without_committing_or_rolling_back(?string $begin): void
    {
        $observer = $this->lab->store();
        $price = $this->price();
        $calls = 0;
        $begin === null ? $this->pdo->beginTransaction() : $this->pdo->exec($begin);
        try {
            $this->rawInsert('b221_price_versions', PricingValues::price($price));
            try {
                $this->store->atomic(function () use (&$calls) {
                    $calls++;
                });
                $this->fail('A caller transaction must be rejected.');
            } catch (LogicException) {
                $this->assertSame(0, $calls);
                $this->assertSame([], $this->store->busyEvents());
                $this->assertEquals($price, $this->store->latestPrice(1001));
                $this->assertNull($observer->latestPrice(1001));
                $this->assertTrue($this->lab->owns($this->pdo));
            }
        } finally {
            $begin === null ? $this->pdo->rollBack() : $this->pdo->exec('ROLLBACK');
        }
        $this->assertNull($this->store->latestPrice(1001));
        $this->assertNull($observer->latestPrice(1001));
        $this->store->atomic(function () use ($price, &$calls) {
            $calls++;
            $this->store->appendPrice($price);
        });
        $this->assertSame(1, $calls);
        $this->assertEquals($price, $observer->latestPrice(1001));
    }

    public function test_manual_exclusive_transaction_remains_under_caller_control(): void
    {
        $observer = $this->lab->store();
        $price = $this->price();
        $calls = 0;
        $this->pdo->exec('BEGIN EXCLUSIVE');
        try {
            $this->rawInsert('b221_price_versions', PricingValues::price($price));
            try {
                $this->store->atomic(function () use (&$calls) {
                    $calls++;
                });
                $this->fail('The exclusive transaction belongs to the caller.');
            } catch (LogicException) {
                $this->assertSame(0, $calls);
                $this->assertEquals($price, $this->store->latestPrice(1001));
            }
            try {
                $observer->latestPrice(1001);
                $this->fail('An exclusive transaction must still block other connections.');
            } catch (PDOException $error) {
                $this->assertSame(5, $error->errorInfo[1]);
            }
        } finally {
            $this->pdo->exec('ROLLBACK');
        }
        $this->assertNull($observer->latestPrice(1001));
        $this->assertNull($this->store->latestPrice(1001));
    }

    public static function structuralErrors(): array
    {
        return [
            'missing table' => ['INSERT INTO main.b221_missing_table VALUES (1)', 'no such table: main.b221_missing_table'],
            'syntax error' => ['SELEC 1', 'near "SELEC": syntax error'],
            'nested BEGIN inside owned body' => ['BEGIN IMMEDIATE', 'cannot start a transaction within a transaction'],
        ];
    }

    #[DataProvider('structuralErrors')]
    public function test_sqlite_error_one_in_the_body_remains_the_original_pdo_exception(string $sql, string $message): void
    {
        $price = $this->price();
        $calls = 0;
        $original = null;
        try {
            $this->store->atomic(function () use ($price, $sql, &$calls, &$original) {
                $calls++;
                $this->store->appendPrice($price);
                try {
                    $this->pdo->exec($sql);
                } catch (PDOException $error) {
                    $original = $error;
                    throw $error;
                }
            });
            $this->fail('The structural error must propagate.');
        } catch (PDOException $error) {
            $this->assertSame($original, $error);
            $this->assertSame(['HY000', 1, $message], $error->errorInfo);
        }
        $this->assertSame(1, $calls);
        $this->assertSame([], $this->store->busyEvents());
        $this->assertNull($this->lab->store()->latestPrice(1001));
        $this->store->atomic(fn () => $this->store->appendPrice($price));
        $this->assertEquals($price, $this->lab->store()->latestPrice(1001));
    }

    public function test_readonly_begin_error_does_not_become_external_transaction_rejection(): void
    {
        $calls = 0;
        $this->pdo->exec('PRAGMA query_only = ON');
        try {
            try {
                $this->store->atomic(function () use (&$calls) {
                    $calls++;
                });
                $this->fail('A readonly connection must not acquire a write transaction.');
            } catch (PDOException $error) {
                $this->assertSame(8, $error->errorInfo[1]);
                $this->assertSame(0, $calls);
                $this->assertSame([], $this->store->busyEvents());
                $this->assertTrue($this->lab->owns($this->pdo));
            }
        } finally {
            $this->pdo->exec('PRAGMA query_only = OFF');
        }
        $this->assertSame('normal', $this->store->atomic(fn () => 'normal'));
    }

    public function test_normal_atomic_retains_immediate_writer_lock_and_callback_once(): void
    {
        $competitor = $this->lab->store(attempts: 1);
        $calls = 0;
        $competingCalls = 0;
        $this->store->atomic(function () use ($competitor, &$calls, &$competingCalls) {
            $calls++;
            // No write precedes this: BEGIN itself must have acquired the writer lock.
            try {
                $competitor->atomic(function () use (&$competingCalls) {
                    $competingCalls++;
                });
                $this->fail('BEGIN IMMEDIATE must already exclude another writer.');
            } catch (SqlitePricingBusy $error) {
                $this->assertSame(['begin', 1], [$error->phase, $error->attempts]);
                $this->assertSame([['phase' => 'begin', 'attempt' => 1, 'code' => 5]], $competitor->busyEvents());
                $this->assertSame(0, $competingCalls);
            }
        });
        $this->assertSame(1, $calls);
        $this->assertSame('recovered', $competitor->atomic(fn () => 'recovered'));
    }

    public function test_invalidated_connection_is_rejected_without_touching_callers_transaction(): void
    {
        $sameConnection = $this->lab->store($this->pdo);
        $observer = $this->lab->store();
        $price = $this->price();
        $calls = 0;
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $this->rawInsert('b221_price_versions', PricingValues::price($price));
            $this->lab->invalidate($this->pdo);
            foreach ([$this->store, $sameConnection] as $store) {
                try {
                    $store->atomic(function () use (&$calls) {
                        $calls++;
                    });
                    $this->fail('An invalidated connection cannot start work.');
                } catch (LogicException) {
                    $this->assertSame(0, $calls);
                }
                try {
                    $store->latestPrice(1001);
                    $this->fail('An invalidated connection cannot serve reads.');
                } catch (LogicException) {
                    $this->assertFalse($this->lab->owns($this->pdo));
                }
            }
            try {
                $this->lab->store($this->pdo);
                $this->fail('The factory must not re-adopt an invalidated connection.');
            } catch (LogicException) {
                $this->assertSame(1, $this->pdo->query('SELECT count(*) FROM main.b221_price_versions')->fetchColumn());
                $this->assertNull($observer->latestPrice(1001));
            }
        } finally {
            $this->pdo->exec('ROLLBACK');
        }
        $this->assertNull($observer->latestPrice(1001));
        $observer->atomic(fn () => $observer->appendPrice($price));
        $this->assertEquals($price, $this->lab->store()->latestPrice(1001));
    }
}
