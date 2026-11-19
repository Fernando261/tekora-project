<?php

namespace Tests\Feature;

use App\Payments\LocalCheckoutAttemptStore;
use App\Services\ReservationWorkflow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\SlotHoldFixtures;
use Tests\TestCase;

class LocalIntegratedCheckoutHttpTest extends TestCase
{
    use SlotHoldFixtures;

    private string $fixtureDir;

    protected function setUp(): void
    {
        parent::setUp(); // Migrates only memory SQLite from Tests\TestCase.
        $this->fixtureDir = realpath(sys_get_temp_dir()).DIRECTORY_SEPARATOR.'tekora-b32-'.bin2hex(random_bytes(12));
        if (! mkdir($this->fixtureDir, 0700)) {
            throw new \RuntimeException('No se pudo preparar SQLite sintética de prueba.');
        }
        $db = $this->fixtureDir.DIRECTORY_SEPARATOR.'tekora.sqlite';
        $pdo = DB::connection('sqlite')->getPdo();
        $pdo->exec("VACUUM main INTO ".$pdo->quote($db));
        file_put_contents($this->fixtureDir.DIRECTORY_SEPARATOR.'.tekora-b32-synthetic', "TEST ONLY\n");
        DB::purge('sqlite');
        config(['database.connections.sqlite.database' => $db]);
        $this->withServerVariables(['HTTP_HOST' => 'localhost', 'REMOTE_ADDR' => '127.0.0.1']);
    }

    protected function tearDown(): void
    {
        DB::purge('sqlite');
        parent::tearDown();
        // Remove only the brand-new, exclusively owned temporary test files.
        foreach (['tekora.sqlite', 'tekora.sqlite-wal', 'tekora.sqlite-shm', '.tekora-b32-synthetic'] as $name) {
            $path = $this->fixtureDir.DIRECTORY_SEPARATOR.$name;
            if (is_file($path) && ! is_link($path)) {
                @unlink($path);
            }
        }
        @rmdir($this->fixtureDir);
    }

    private function held(): array
    {
        [$client, $provider, , , $reservation] = $this->pricedProposal();
        app(ReservationWorkflow::class)->decide($provider, $reservation->id, 'confirm', $this->holdInput());

        return [$client, $provider, $reservation];
    }

    public function test_client_checkout_renders_real_accepted_quote_and_records_only_synthetic_outcome(): void
    {
        [$client, $provider, $reservation] = $this->held();
        $this->actingAs($client, 'web')->get(route('checkout.local.show', $reservation->id))
            ->assertOk()->assertSee('Finaliza tu contratación')->assertSee('$1,000.00');
        $this->post(route('checkout.local.submit', $reservation->id), [
            'method' => 'card', 'card_number' => '4242424242424242',
            'expiry' => '12/30', 'cvv' => '123', 'idempotency_key' => (string) Str::uuid(),
        ])->assertRedirect(route('checkout.local.show', $reservation->id));
        $this->get(route('checkout.local.show', $reservation->id))
            ->assertOk()->assertSee('Tarjeta aprobada');
        $this->assertSame('test_approved', app(LocalCheckoutAttemptStore::class)->current($reservation->id)['status']);
        $this->assertSame('held', $reservation->fresh()->effectiveStatus()->value);
        $this->actingAs($provider, 'web')->get(route('checkout.local.show', $reservation->id))->assertForbidden();
    }

    public function test_provider_receives_cash_and_client_cannot_use_provider_command(): void
    {
        [$client, $provider, $reservation] = $this->held();
        $this->actingAs($client, 'web')->post(route('checkout.local.submit', $reservation->id), [
            'method' => 'cash', 'idempotency_key' => (string) Str::uuid(),
        ])->assertRedirect();
        $this->post(route('checkout.local.cash.receive', $reservation->id))->assertForbidden();
        $this->actingAs($provider, 'web')->get(route('checkout.local.cash', $reservation->id))
            ->assertOk()->assertSee('Pendiente de recepción');
        $this->post(route('checkout.local.cash.receive', $reservation->id))->assertRedirect();
        $this->actingAs($client, 'web')->get(route('checkout.local.show', $reservation->id))
            ->assertOk()->assertSee('Efectivo recibido por el prestador');
        $this->assertSame('held', $reservation->fresh()->effectiveStatus()->value);
    }

    public function test_invalid_card_or_expired_hold_cannot_change_attempt(): void
    {
        [$client, , $reservation] = $this->held();
        $this->actingAs($client, 'web')->post(route('checkout.local.submit', $reservation->id), [
            'method' => 'card', 'card_number' => '4111111111111111',
            'expiry' => '12/30', 'cvv' => '123', 'idempotency_key' => (string) Str::uuid(),
        ])->assertSessionHasErrors('card_number');
        $this->assertNull(app(LocalCheckoutAttemptStore::class)->current($reservation->id));
        $this->travel(900)->seconds();
        $this->post(route('checkout.local.submit', $reservation->id), [
            'method' => 'cash', 'idempotency_key' => (string) Str::uuid(),
        ])->assertStatus(409);
        $this->assertNull(app(LocalCheckoutAttemptStore::class)->current($reservation->id));
    }
}
