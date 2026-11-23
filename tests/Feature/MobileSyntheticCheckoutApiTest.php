<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Http\Controllers\LocalIntegratedCheckoutController;
use App\Payments\LocalCheckoutAttemptStore;
use App\Services\ReservationWorkflow;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\SlotHoldFixtures;
use Tests\TestCase;

final class MobileSyntheticCheckoutApiTest extends TestCase
{
    use SlotHoldFixtures;

    private ?string $fixtureDir = null;

    protected function tearDown(): void
    {
        if ($this->fixtureDir !== null) {
            Auth::forgetGuards();
            DB::purge('sqlite');
            $this->app->forgetScopedInstances();
            parent::tearDown();
            foreach (['tekora.sqlite', 'tekora.sqlite-journal', 'tekora.sqlite-wal',
                'tekora.sqlite-shm', '.tekora-b32-synthetic'] as $name) {
                $path = $this->fixtureDir.DIRECTORY_SEPARATOR.$name;
                if (is_file($path)) {
                    unlink($path);
                }
            }
            rmdir($this->fixtureDir);

            return;
        }
        parent::tearDown();
    }

    private function held(): array
    {
        $data = $this->pricedProposal();
        app(ReservationWorkflow::class)->decide($data[1], $data[4]->id, 'confirm', $this->holdInput());

        return $data;
    }

    private function localFixture(): void
    {
        DB::statement("CREATE TABLE synthetic_checkout_attempts (
            reservation_id INTEGER PRIMARY KEY, client_user_id INTEGER NOT NULL,
            provider_user_id INTEGER NOT NULL, quote_id TEXT NOT NULL,
            amount_cents INTEGER NOT NULL CHECK(amount_cents > 0),
            method TEXT NOT NULL CHECK(method IN ('card','cash')),
            scenario TEXT NOT NULL,
            status TEXT NOT NULL CHECK(status IN ('test_approved','declined','temporary_failure','awaiting_cash','cash_received')),
            idempotency_key TEXT NOT NULL, reference TEXT NOT NULL UNIQUE,
            attempted_at TEXT NOT NULL, received_at TEXT NULL
        )");
        $root = realpath(sys_get_temp_dir());
        $dir = $root.DIRECTORY_SEPARATOR.'tekora-b32-'.bin2hex(random_bytes(12));
        $this->assertTrue(mkdir($dir, 0700));
        $this->fixtureDir = $dir;
        $pdo = DB::connection()->getPdo();
        $pdo->exec('VACUUM main INTO '.$pdo->quote($dir.DIRECTORY_SEPARATOR.'tekora.sqlite'));
        $pdo = null;
        file_put_contents($dir.DIRECTORY_SEPARATOR.'.tekora-b32-synthetic', "M3.1 TEST ONLY\n");
        DB::purge('sqlite');
        $this->app->forgetScopedInstances();
        config(['database.connections.sqlite.database' => $dir.DIRECTORY_SEPARATOR.'tekora.sqlite']);
        $this->withServerVariables(['HTTP_HOST' => 'localhost', 'REMOTE_ADDR' => '127.0.0.1']);
        $this->assertTrue(LocalIntegratedCheckoutController::permitsSyntheticRequest(
            Request::create('http://localhost/', server: ['REMOTE_ADDR' => '127.0.0.1'])));
    }

    private function url(int $id): string
    {
        return '/api/v1/reservations/'.$id.'/demo-checkout';
    }

    public function test_normal_database_exposes_no_synthetic_selection_or_write(): void
    {
        [$client, , , , $reservation] = $this->held();
        $url = $this->url($reservation->id);
        $this->getJson($url, $this->bearer($client))->assertNotFound();
        $this->postJson($url, ['method' => 'cash', 'idempotency_key' => (string) Str::uuid()],
            $this->bearer($client))->assertNotFound();
        $this->assertSame('held', $reservation->fresh()->effectiveStatus()->value);
    }

    public function test_only_fixture_owner_can_choose_card_and_failed_attempt_never_confirms(): void
    {
        [$client, $provider, , , $reservation] = $this->held();
        $this->localFixture();
        $url = $this->url($reservation->id);
        $this->getJson($url)->assertUnauthorized();
        $this->getJson($url, $this->bearer($provider))->assertNotFound();
        $this->postJson($url, ['method' => 'cash', 'idempotency_key' => (string) Str::uuid()],
            $this->bearer($provider))->assertNotFound();
        // This private endpoint intentionally rejects GET bodies; getJson() sends [] in tests.
        $this->get($url, $this->bearer($client) + ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('data.status', 'not_selected')
            ->assertJsonPath('data.quote_id', $reservation->quote_id)
            ->assertJsonPath('data.reservation_status', 'held')
            ->assertJsonPath('data.selection_verified', false)
            ->assertJsonPath('data.can_choose', true)
            ->assertJsonPath('data.can_confirm', false);
        $this->postJson($url, ['method' => 'cash', 'idempotency_key' => (string) Str::uuid(), 'amount_cents' => 1],
            $this->bearer($client))->assertUnprocessable();
        $this->postJson($url, [
            'method' => 'card', 'card_number' => '4111111111111111', 'expiry' => '12/30', 'cvv' => '123',
            'idempotency_key' => (string) Str::uuid(),
        ], $this->bearer($client))->assertUnprocessable();
        $failed = [
            'method' => 'card', 'card_number' => '4000000000000002', 'expiry' => '12/30', 'cvv' => '123',
            'idempotency_key' => (string) Str::uuid(),
        ];
        $this->postJson($url, $failed, $this->bearer($client))->assertOk()
            ->assertJsonPath('data.status', 'declined')
            ->assertJsonPath('data.selection_verified', false)
            ->assertJsonPath('data.can_confirm', false);
        $this->patchJson('/api/v1/reservations/'.$reservation->id.'/commit',
            $this->holdInput(['version' => $reservation->fresh()->version]),
            $this->bearer($client))->assertConflict();

        $approved = [
            'method' => 'card', 'card_number' => '4242424242424242', 'expiry' => '12/30', 'cvv' => '123',
            'idempotency_key' => (string) Str::uuid(),
        ];
        $response = $this->postJson($url, $approved, $this->bearer($client))->assertOk()
            ->assertJsonPath('data.status', 'test_approved')
            ->assertJsonPath('data.selection_verified', true)
            ->assertJsonPath('data.can_confirm', true);
        foreach (['card_number', 'cvv', '4242424242424242', 'idempotency_key'] as $sensitive) {
            $this->assertStringNotContainsString($sensitive, $response->getContent());
        }
        $this->postJson($url, $approved, $this->bearer($client))->assertOk()
            ->assertJsonPath('data.reference', $response->json('data.reference'));
        $this->assertDatabaseCount('synthetic_checkout_attempts', 1);
        $this->patchJson('/api/v1/reservations/'.$reservation->id.'/commit',
            $this->holdInput(['version' => $reservation->fresh()->version]),
            $this->bearer($client))->assertOk()->assertJsonPath('data.status', 'confirmed');
        // After commit, the read API preserves the verified attempt for the quote UI.
        $this->get($url, $this->bearer($client) + ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('data.quote_id', $reservation->quote_id)
            ->assertJsonPath('data.reservation_status', 'confirmed')
            ->assertJsonPath('data.status', 'test_approved')
            ->assertJsonPath('data.selection_verified', true)
            ->assertJsonPath('data.can_confirm', false);
    }

    public function test_cash_pending_allows_booking_but_not_cash_received(): void
    {
        [$client, , , , $reservation] = $this->held();
        $this->localFixture();
        $url = $this->url($reservation->id);
        $this->postJson($url, ['method' => 'cash', 'idempotency_key' => (string) Str::uuid()],
            $this->bearer($client))->assertOk()
            ->assertJsonPath('data.status', 'awaiting_cash')
            ->assertJsonPath('data.selection_verified', true)
            ->assertJsonPath('data.can_confirm', true);
        $this->assertSame('awaiting_cash', app(LocalCheckoutAttemptStore::class)->current($reservation->id)['status']);
        $this->patchJson('/api/v1/reservations/'.$reservation->id.'/commit',
            $this->holdInput(['version' => $reservation->fresh()->version]),
            $this->bearer($client))->assertOk()->assertJsonPath('data.status', 'confirmed');
        $this->assertSame('awaiting_cash', app(LocalCheckoutAttemptStore::class)->current($reservation->id)['status']);
        $this->get($url, $this->bearer($client) + ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('data.quote_id', $reservation->quote_id)
            ->assertJsonPath('data.reservation_status', 'confirmed')
            ->assertJsonPath('data.status', 'awaiting_cash')
            ->assertJsonPath('data.selection_verified', true);
    }
}
