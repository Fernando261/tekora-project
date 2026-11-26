<?php

declare(strict_types=1);

namespace Tests\PaymentsIntegrationPrototype;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PaymentsIntegration\IntegrationTestCase;

#[\PHPUnit\Framework\Attributes\Group('b231-D-transactions')]
final class JointRollbackTest extends IntegrationTestCase
{
    public static function earlyStages(): array
    {
        return [['price'], ['quote'], ['consent'], ['binding']];
    }

    #[DataProvider('earlyStages')]
    public function test_t24_each_legitimate_command_rolls_back_its_own_effects(string $stage): void
    {
        $f = $this->faultAt($stage);
        if ($stage === 'price') {
            $this->rejects(fn () => $f->publish(101, 1, 1001, 0, $this->key()), \RuntimeException::class);
            self::assertSame(0, $this->rowCount('prices'));

            return;
        }
        $this->f->publish(101, 1, 1001, 0, $this->key());
        $prices = $this->rows('prices');
        if ($stage === 'quote') {
            $this->rejects(fn () => $f->quote(201, 11, $this->key()), \RuntimeException::class);
            self::assertSame(0, $this->rowCount('quotes'));
        } else {
            $q = $this->f->quote(201, 11, $this->key())->historical['quote_id'];
            $quotes = $this->rows('quotes');
            $this->rejects(fn () => $f->propose(201, $q, 1, $this->start(), $this->key()), \RuntimeException::class);
            self::assertSame($quotes, $this->rows('quotes'));
            self::assertSame(0, $this->rowCount('acceptances'));
            self::assertSame(0, $this->rowCount('proposals'));
        }
        self::assertSame($prices, $this->rows('prices'));
    }

    public static function acceptStages(): array
    {
        return [['activation'], ['hold'], ['claim'], ['head']];
    }

    #[DataProvider('acceptStages')]
    public function test_t25_provider_command_has_no_partial_effects(string $stage): void
    {
        $this->proposal();
        $previous = [];
        foreach (['prices', 'quotes', 'acceptances', 'proposals', 'bindings', 'commands', 'heads', 'proposal_events'] as $table) {
            $previous[$table] = $this->rows($table);
        }
        $this->rejects(fn () => $this->faultAt($stage)->accept(101, 1, 1, $this->key()), \RuntimeException::class);
        foreach ($previous as $t => $rows) {
            self::assertSame($rows, $this->rows($t));
        }
        foreach (['activations', 'holds', 'claims', 'hold_events', 'claim_events'] as $t) {
            self::assertSame(0, $this->rowCount($t));
        }
    }

    public function test_t27_partial_commit_without_receipt_is_rejected_by_deferred_fk(): void
    {
        $p = $this->lab->connect();
        $p->exec('BEGIN IMMEDIATE');
        $q = $p->prepare("INSERT INTO b231_prices VALUES(?,1,11,1,1001,'MXN',60,101,?,'2026-11-12 10:00:00.000000',?)");
        $q->execute([$this->key(), $this->key(), $this->key()]);
        $q->closeCursor();
        $this->rejects(fn () => $p->exec('COMMIT'), \PDOException::class);
        $p->exec('ROLLBACK');
        self::assertSame(0, $this->rowCount('prices'));
    }

    public function test_t27_projection_cannot_confirm_without_claim(): void
    {
        $this->proposal();
        $p = $this->lab->connect();
        $p->exec('BEGIN IMMEDIATE');
        $this->rejects(fn () => $p->exec("UPDATE b231_heads SET state='awaiting_payment',version=2,command_id='".$this->key()."' WHERE id=1"), \PDOException::class);
        $p->exec('ROLLBACK');
    }

    public function test_t27_hold_cannot_change_without_reciprocal_claim_and_head(): void
    {
        $this->held();
        $p = $this->lab->connect();
        $p->exec('BEGIN IMMEDIATE');
        $cmd = $this->key();
        $q = $p->prepare("UPDATE b231_holds SET state='committed',version=2,actor_kind='system',actor_user_id=NULL,command_id=? WHERE reservation_id=1");
        $q->execute([$cmd]);
        $q->closeCursor();
        $q = $p->prepare("INSERT INTO b231_commands VALUES(?,'hold','system:system',? ,?,'probe','success','{\"code\":\"probe\"}',?,'system',NULL)");
        $q->execute([$cmd, $this->key(), str_repeat('a', 64), $this->clock->us]);
        $q->closeCursor();
        $this->rejects(fn () => $p->exec('COMMIT'), \PDOException::class);
        $p->exec('ROLLBACK');
        self::assertSame('held', $this->rows('holds')[0]['state']);
    }

    public function test_t25_failure_after_receipt_rolls_back_complete_command(): void
    {
        $this->proposal();
        $commands = $this->rows('commands');
        $unit = new \Tests\Support\PaymentsIntegration\SharedSqliteUnitOfWork($this->lab, fn () => $this->clock->now(), fault: static function ($phase): void {
            if ($phase === 'after_seal') {
                throw new \RuntimeException('after receipt');
            }
        });
        $this->rejects(fn () => (new \Tests\Support\PaymentsIntegration\ScenarioFixture($unit))->accept(101, 1, 1, $this->key()), \RuntimeException::class);
        self::assertSame(0, $this->rowCount('holds'));
        self::assertSame($commands, $this->rows('commands'));
        $unit->close();
    }
}
