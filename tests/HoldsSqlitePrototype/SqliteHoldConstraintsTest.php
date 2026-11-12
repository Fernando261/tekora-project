<?php

declare(strict_types=1);

namespace Tests\HoldsSqlitePrototype;

use App\Scheduling\Holds\HoldActor;
use App\Scheduling\Holds\HoldEvent;
use App\Scheduling\Holds\HoldState;
use App\Scheduling\Holds\SlotHold;
use LogicException;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\HoldsSqlite\HoldFixture;
use Tests\Support\HoldsSqlite\HoldValues;
use Tests\Support\HoldsSqlite\SyntheticHoldDatabase;

#[Group('b222-sqlite-single')]
final class SqliteHoldConstraintsTest extends TestCase
{
    use HoldFixture;

    public function test_schema_has_only_five_strict_lab_tables_and_effective_controls(): void
    {
        $tables = $this->pdo->query("SELECT name,strict FROM pragma_table_list WHERE schema='main' AND name GLOB 'b222_*'")->fetchAll();
        $this->assertCount(5, $tables);
        $this->assertSame([1], array_values(array_unique(array_column($tables, 'strict'))));
        foreach (['foreign_keys' => 1, 'ignore_check_constraints' => 0, 'recursive_triggers' => 0, 'user_version' => 222, 'busy_timeout' => 0, 'synchronous' => 2] as $k => $v) {
            $this->assertSame($v, $this->pdo->query('PRAGMA '.$k)->fetchColumn());
        }
        $this->assertSame('delete', $this->pdo->query('PRAGMA journal_mode')->fetchColumn());
        $this->assertSame('UTF-8', $this->pdo->query('PRAGMA encoding')->fetchColumn());
    }

    public static function badRecords(): array
    {
        return [
            ['hold_id', 'nul'], ['hold_id', 'upper'], ['hold_id', null], ['hold_id', str_repeat('a', 36)],
            ['quote_ref', 'nul'], ['quote_ref', 'bad space'], ['quote_ref', ''], ['quote_ref', str_repeat('x', 129)],
            ['reservation_id', 0], ['proposal_version', -1], ['service_id', null], ['client_user_id', 110],
            ['starts_at_us', 1.5], ['starts_at_us', 'bad'], ['ends_at_us', 0], ['accepted_at_us', null], ['expires_at_us', 1],
            ['updated_at_us', 0], ['state', 'committed'], ['state', 'nul'], ['version', 2], ['actor_kind', 'client'], ['actor_user_id', 999],
        ];
    }

    #[DataProvider('badRecords')]
    public function test_sql_rejects_malformed_or_inconsistent_hold_records(string $field, mixed $bad): void
    {
        $this->pdo->exec('INSERT INTO main.b222_provider_identities VALUES(10,110)');
        $h = SlotHold::start($this->proposal(), $this->clock->time);
        $this->store->atomic(fn () => $this->store->observeClock($this->clock->time));
        $row = HoldValues::row($h, new HoldEvent($h->id, 1, null, HoldState::Held, HoldActor::provider(110), $this->clock->time));
        $row[$field] = match ($bad) {
            'nul' => (string) $row[$field]."\0x",'upper' => strtoupper($row[$field]),default => $bad
        };
        // UUID random hex could contain only digits with astronomically small probability; use a fixed noncanonical letter.
        if ($bad === 'upper') {
            $row[$field] = 'ABCDEFAB-ABCD-4000-8000-000000000001';
        }
        $before = $this->facts();
        try {
            $this->rawInsert('b222_holds', $row);
            $this->fail('Malformed SQL input accepted');
        } catch (PDOException) {
            $this->assertSame($before, $this->facts());
        }
    }

    public static function historyTargets(): array
    {
        return [['b222_holds'], ['b222_hold_events'], ['b222_hold_receipts'], ['b222_provider_identities']];
    }

    #[DataProvider('historyTargets')]
    public function test_update_delete_and_replace_cannot_rewrite_history(string $table): void
    {
        $h = $this->acquire();
        $before = $this->facts();
        $row = $before[$table][0];
        $pk = array_key_first($row);
        foreach (['update', 'delete', 'replace'] as $operation) {
            try {
                match ($operation) {
                    'replace' => $this->rawInsert($table, $row, 'INSERT OR REPLACE'),'update' => $this->pdo->exec('UPDATE main.'.$table.' SET '.$pk.'='.$pk),default => $this->pdo->exec('DELETE FROM main.'.$table)
                };
                $this->fail('History rewrite accepted');
            } catch (PDOException) {
                $this->assertSame($before, $this->facts());
            }
        }
        $this->assertEquals($h, $this->store->find($h->id));
    }

    public static function providerCollisions(): array
    {
        return [[10, 120], [20, 110], [10, 110]];
    }

    #[DataProvider('providerCollisions')]
    public function test_identity_replace_rejects_all_unique_collisions(int $profile, int $user): void
    {
        $this->pdo->exec('INSERT INTO main.b222_provider_identities VALUES(10,110)');
        $before = $this->facts();
        try {
            $this->rawInsert('b222_provider_identities', ['provider_profile_id' => $profile, 'provider_user_id' => $user], 'INSERT OR REPLACE');
            $this->fail();
        } catch (PDOException) {
            $this->assertSame($before, $this->facts());
        }
        $this->pdo->exec('INSERT INTO main.b222_provider_identities VALUES(20,120)');
        $this->assertSame(2, $this->pdo->query('SELECT count(*) FROM main.b222_provider_identities')->fetchColumn());
    }

    public function test_direct_sql_overlap_guard_and_foreign_keys_do_not_depend_on_engine_check(): void
    {
        $this->acquire();
        $before = $this->facts();
        $h = SlotHold::start($this->proposal(2), $this->clock->time);
        $row = HoldValues::row($h, new HoldEvent($h->id, 1, null, HoldState::Held, HoldActor::provider(110), $this->clock->time));
        try {
            $this->rawInsert('b222_holds', $row);
            $this->fail();
        } catch (PDOException) {
            $this->assertSame($before, $this->facts());
        }
        $h = SlotHold::start($this->proposal(3, '11:00:00.000001', 20), $this->clock->time);
        $row = HoldValues::row($h, new HoldEvent($h->id, 1, null, HoldState::Held, HoldActor::provider(120), $this->clock->time));
        try {
            $this->rawInsert('b222_holds', $row);
            $this->fail();
        } catch (PDOException) {
            $this->assertSame($before, $this->facts());
        }
    }

    public static function snapshotDamage(): array
    {
        return [['version'], ['clock'], ['event_count'], ['event_actor'], ['event_from'], ['event_time']];
    }

    #[DataProvider('snapshotDamage')]
    public function test_reconstruction_rejects_inconsistent_values_without_mutating_database(string $damage): void
    {
        $h = $this->acquire();
        $row = $this->pdo->query('SELECT * FROM main.b222_holds')->fetch();
        $events = $this->store->history($h->id);
        switch ($damage) {
            case 'version':$row['version'] = 2;
                break;
            case 'clock':$row['expires_at_us']++;
                break;
            case 'event_count':$events = [];
                break;
            case 'event_actor':$events = [new HoldEvent($h->id, 1, null, HoldState::Held, HoldActor::provider(999), $h->acceptedAt)];
                break;
            case 'event_from':$events = [new HoldEvent($h->id, 1, HoldState::Held, HoldState::Held, HoldActor::provider(110), $h->acceptedAt)];
                break;
            case 'event_time':$events = [new HoldEvent($h->id, 1, null, HoldState::Held, HoldActor::provider(110), $h->acceptedAt->modify('+1 microsecond'))];
                break;
        }
        $this->expectException(LogicException::class);
        HoldValues::hydrate($row, $events);
    }

    public function test_receipts_validate_semantic_hash_not_only_sql_shape(): void
    {
        $h = $this->acquire();
        $r = $this->pdo->query('SELECT * FROM main.b222_hold_receipts')->fetch();
        $r['scope_key'] = 'provider:110:'.$this->key(99);
        $r['intention_uuid'] = $this->key(99);
        $r['fingerprint'] = str_repeat('a', 64);
        $this->rawInsert('b222_hold_receipts', $r); // SQLite has no built-in SHA-256; hydration must detect the invalid semantic claim.
        $this->expectException(LogicException::class);
        $this->store->receipt($r['scope_key']);
    }

    public function test_clock_cannot_be_replaced_or_moved_back_and_wrong_provider_identity_is_rejected(): void
    {
        $h = $this->acquire();
        $before = $this->facts();
        foreach (['UPDATE main.b222_clock SET last_observed_at_us=last_observed_at_us-1', 'INSERT OR REPLACE INTO main.b222_clock SELECT * FROM main.b222_clock', 'DELETE FROM main.b222_clock'] as $sql) {
            try {
                $this->pdo->exec($sql);
                $this->fail();
            } catch (PDOException) {
                $this->assertSame($before, $this->facts());
            }
        }
        $p = $this->proposal(2, '11:00:00.000001');
        $q = new \App\Scheduling\Holds\AcceptedQuoteReference('quote-2', 102, 1002, 10, 111, 902);
        $p = new \App\Scheduling\Holds\ReservationProposal(2, 1, $p->interval, $q);
        $this->violation('provider_identity_conflict', fn () => $this->engine->accept($p, HoldActor::provider(111), $this->key(2)));
        $this->assertEquals($h, $this->store->find($h->id));
    }

    public function test_foreign_connections_and_invalid_run_capabilities_are_not_adopted(): void
    {
        $other = SyntheticHoldDatabase::create();
        try {
            $this->lab->store($other->connect());
            $this->fail();
        } catch (LogicException) {
            $this->assertSame([], $this->store->all());
        }
        $ticket = $this->lab->ticket();
        $ticket['run'] = str_repeat('0', 64);
        $this->expectException(LogicException::class);
        SyntheticHoldDatabase::resume($ticket);
    }

    public function test_integer_primary_keys_never_autogenerate_synthetic_identity_or_clock(): void
    {
        foreach (['b222_provider_identities' => ['provider_profile_id' => null, 'provider_user_id' => 110], 'b222_clock' => ['singleton' => null, 'last_observed_at_us' => HoldValues::micros($this->clock->time)]] as $table => $row) {
            try {
                $this->rawInsert($table, $row);
                $this->fail('Explicit NULL must not generate a different synthetic identity.');
            } catch (PDOException) {
                $this->assertSame(0, $this->pdo->query('SELECT count(*) FROM main.'.$table)->fetchColumn());
            }
        }
    }
}
