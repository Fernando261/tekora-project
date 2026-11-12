<?php

declare(strict_types=1);

namespace Tests\PaymentsSqlitePrototype;

use App\Payments\PricingConflict;
use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\PricingSqlite\PricingFixture;
use Tests\Support\PricingSqlite\PricingValues;
use Tests\Support\PricingSqlite\SqlitePricingBusy;
use Tests\Support\PricingSqlite\SqlitePricingStore;
use Tests\Support\PricingSqlite\SyntheticPricingDatabase;

final class PricingSqliteConcurrencyTest extends TestCase
{
    use PricingFixture;

    public function test_independent_connections_do_not_see_an_uncommitted_price(): void
    {
        $other = $this->lab->store();
        $price = $this->price();
        $this->store->atomic(function () use ($other, $price) {
            $this->store->appendPrice($price);
            $this->assertNull($other->latestPrice(1001));
        });
        $this->assertEquals($price, $other->latestPrice(1001));
    }

    public function test_busy_begin_has_bounded_attempts_and_does_not_run_callback(): void
    {
        $other = $this->lab->store();
        $calls = 0;
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            try {
                $other->atomic(function () use (&$calls) {
                    $calls++;
                });
                $this->fail('A concurrent writer must not acquire the lock.');
            } catch (SqlitePricingBusy $error) {
                $this->assertSame(['begin', 3], [$error->phase, $error->attempts]);
                $this->assertSame(0, $calls);
                $this->assertCount(3, $other->busyEvents());
            }
        } finally {
            $this->pdo->exec('ROLLBACK');
        }
        $this->assertSame('recovered', $other->atomic(fn () => 'recovered'));
    }

    public function test_begin_retry_after_lock_release_executes_body_once(): void
    {
        $calls = 0;
        $other = $this->lab->store(onBusy: function (string $phase, int $attempt) {
            $this->assertSame(['begin', 1], [$phase, $attempt]);
            $this->pdo->exec('COMMIT');
        });
        $this->pdo->exec('BEGIN IMMEDIATE');
        $price = $this->price();
        $other->atomic(function () use ($other, $price, &$calls) {
            $calls++;
            $other->appendPrice($price);
        });
        $this->assertSame(1, $calls);
        $this->assertCount(1, $other->busyEvents());
        $this->assertEquals($price, $this->store->latestPrice(1001));
    }

    public function test_two_writers_cannot_publish_the_same_next_version(): void
    {
        $other = $this->lab->store();
        $winner = $this->price();
        $loser = $this->price();
        $this->store->atomic(function () use ($winner, $other, $loser) {
            $this->store->appendPrice($winner);
            try {
                $other->atomic(fn () => $other->appendPrice($loser));
                $this->fail('Second writer must be busy.');
            } catch (SqlitePricingBusy $error) {
                $this->assertSame('begin', $error->phase);
            }
        });
        try {
            $other->atomic(fn () => $other->appendPrice($loser));
            $this->fail('Stale version must fail after acquiring a fresh lock.');
        } catch (PricingConflict) {
            $this->assertSame(1, $other->latestPrice(1001)->version);
        }
        $second = $this->price(version: 2);
        $other->atomic(fn () => $other->appendPrice($second));
        $this->assertSame(2, $this->store->latestPrice(1001)->version);
        $this->assertSame(2, $this->pdo->query('SELECT count(*) FROM main.b221_price_versions')->fetchColumn());
    }

    public function test_busy_commit_retries_commit_without_repeating_callback(): void
    {
        $reader = $this->lab->connect();
        $calls = 0;
        $store = $this->lab->store($this->pdo, onBusy: function (string $phase, int $attempt) use ($reader) {
            $this->assertSame(['commit', 1], [$phase, $attempt]);
            $reader->exec('ROLLBACK');
        });
        $reader->exec('BEGIN');
        $reader->query('SELECT count(*) FROM main.b221_price_versions')->fetchColumn();
        $price = $this->price();
        $store->atomic(function () use ($store, $price, &$calls) {
            $calls++;
            $store->appendPrice($price);
        });
        $this->assertSame(1, $calls);
        $this->assertCount(1, $store->busyEvents());
        $this->assertEquals($price, $this->store->latestPrice(1001));
    }

    public function test_exhausted_commit_busy_rolls_back_all_writes(): void
    {
        $reader = $this->lab->connect();
        $reader->exec('BEGIN');
        $reader->query('SELECT count(*) FROM main.b221_price_versions')->fetchColumn();
        try {
            $this->store->atomic(fn () => $this->store->appendPrice($this->price()));
            $this->fail('Commit must be blocked by the reader.');
        } catch (SqlitePricingBusy $error) {
            $this->assertSame(['commit', 3], [$error->phase, $error->attempts]);
        } finally {
            $reader->exec('ROLLBACK');
        }
        $this->assertNull($this->lab->store()->latestPrice(1001));
        $next = $this->price();
        $this->store->atomic(fn () => $this->store->appendPrice($next));
        $this->assertEquals($next, $this->lab->store()->latestPrice(1001));
    }

    public function test_failure_after_three_appends_rolls_back_every_record(): void
    {
        $price = $this->price();
        $quote = $this->quote($price);
        try {
            $this->store->atomic(function () use ($price, $quote) {
                $this->store->appendPrice($price);
                $this->store->appendQuote($quote);
                $this->store->appendAcceptance(new \App\Payments\QuoteAcceptance($quote->id, 901, $this->at('+2 seconds')));
                throw new RuntimeException('synthetic interruption');
            });
        } catch (RuntimeException $error) {
            $this->assertSame('synthetic interruption', $error->getMessage());
        }
        $reopened = $this->lab->store();
        $this->assertNull($reopened->latestPrice(1001));
        $this->assertNull($reopened->quote($quote->id));
        $this->assertNull($reopened->acceptance($quote->id));
        $reopened->atomic(fn () => $reopened->appendPrice($price));
        $this->assertEquals($price, $reopened->priceByKey(1001, $price->idempotencyKey));
    }

    public function test_constraint_failure_after_a_price_rolls_back_the_price_too(): void
    {
        $price = $this->price();
        try {
            $this->store->atomic(function () use ($price) {
                $this->store->appendPrice($price);
                $this->store->appendQuote($this->quote($price, client: 902));
            });
            $this->fail('Client/inquiry mismatch must fail.');
        } catch (PricingConflict) {
            $this->assertNull($this->store->latestPrice(1001));
        }
    }

    public function test_closing_uncommitted_connection_rolls_back_and_reopening_preserves_commits(): void
    {
        $uncommitted = $this->lab->connect();
        $uncommitted->exec('BEGIN IMMEDIATE');
        $this->rawInsert('b221_price_versions', PricingValues::price($this->price()), $uncommitted);
        unset($uncommitted);
        $this->assertNull($this->lab->store()->latestPrice(1001));
        [$price, $quote, $accepted] = $this->populated();
        $fresh = $this->lab->store();
        $this->assertEquals($price, $fresh->latestPrice(1001));
        $this->assertEquals($quote, $fresh->quote($quote->id));
        $this->assertEquals($accepted, $fresh->acceptance($quote->id));
    }

    public function test_nested_and_external_transactions_are_rejected(): void
    {
        try {
            $this->store->atomic(fn () => $this->store->atomic(fn () => null));
            $this->fail('Nested transactions must not be implicit.');
        } catch (LogicException) {
            $this->assertNull($this->store->latestPrice(1001));
        }
        $this->pdo->beginTransaction();
        try {
            $this->store->atomic(fn () => null);
            $this->fail('External transaction must be rejected.');
        } catch (LogicException) {
            $this->assertTrue($this->pdo->inTransaction());
        } finally {
            $this->pdo->rollBack();
        }
    }

    public function test_foreign_connection_is_not_adopted(): void
    {
        $foreignLab = SyntheticPricingDatabase::create();
        $foreign = $foreignLab->connect();
        $this->expectException(LogicException::class);
        SqlitePricingStore::owned($this->lab, $foreign);
    }

    public function test_adapter_writes_require_an_owned_atomic_scope(): void
    {
        $this->expectException(LogicException::class);
        $this->store->appendPrice($this->price());
    }

    public function test_manual_transaction_is_not_adopted_or_rolled_back_by_the_store(): void
    {
        $observer = $this->lab->store();
        $price = $this->price();
        $calls = 0;
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $this->rawInsert('b221_price_versions', PricingValues::price($price));
            try {
                $this->store->atomic(function () use (&$calls) {
                    $calls++;
                });
                $this->fail('The caller owns the manual transaction.');
            } catch (LogicException) {
                $this->assertSame(0, $calls);
                $this->assertEquals($price, $this->store->latestPrice(1001));
                $this->assertNull($observer->latestPrice(1001));
            }
        } finally {
            $this->pdo->exec('ROLLBACK');
        }
        $this->assertNull($this->store->latestPrice(1001));
        $this->assertNull($observer->latestPrice(1001));
        $this->store->atomic(fn () => $this->store->appendPrice($price));
        $this->assertEquals($price, $observer->latestPrice(1001));
    }

    public function test_lost_transaction_context_requires_reconnection_before_any_read(): void
    {
        $price = $this->price();
        $sameConnection = $this->lab->store($this->pdo);
        try {
            $this->store->atomic(function () use ($price) {
                $this->store->appendPrice($price);
                // Synthetic fault: the transaction owner unexpectedly lost its boundary.
                $this->pdo->exec('COMMIT');
                throw new RuntimeException('synthetic lost context');
            });
            $this->fail('The adapter must not claim a successful rollback.');
        } catch (LogicException $error) {
            $this->assertStringContainsString('Rollback could not be established', $error->getMessage());
        }
        try {
            $this->store->latestPrice(1001);
            $this->fail('An uncertain context must not serve reads.');
        } catch (LogicException) {
            $this->assertEquals($price, $this->lab->store()->priceByKey(1001, $price->idempotencyKey));
        }
        $this->assertFalse($this->lab->owns($this->pdo));
        try {
            $sameConnection->latestPrice(1001);
            $this->fail('Another adapter cannot adopt the same uncertain connection.');
        } catch (LogicException) {
            $this->assertEquals($price, $this->lab->store()->latestPrice(1001));
        }
        $this->expectException(LogicException::class);
        $this->lab->store($this->pdo);
    }
}
