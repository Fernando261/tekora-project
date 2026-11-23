<?php

namespace Tests\Feature;

use App\Payments\LocalCheckoutAttemptStore;
use App\Services\ReservationWorkflow;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\SlotHoldFixtures;
use Tests\TestCase;

class LocalIntegratedCheckoutHttpTest extends TestCase
{
    use SlotHoldFixtures;

    private ?string $fixtureDir = null;

    protected function setUp(): void
    {
        parent::setUp(); // Migrates only memory SQLite from Tests\TestCase.
        $this->fixtureDir = realpath(sys_get_temp_dir()).DIRECTORY_SEPARATOR.'tekora-b32-'.bin2hex(random_bytes(12));

        if (mkdir($this->fixtureDir, 0700) === false) {
            throw new \RuntimeException('No se pudo preparar SQLite sintética de prueba.');
        }
        // The disposable checkout schema must exist BEFORE exporting memory SQLite.
        // It is intentionally absent from application migrations and real databases.
        DB::statement("CREATE TABLE synthetic_checkout_attempts (
            reservation_id INTEGER PRIMARY KEY,
            client_user_id INTEGER NOT NULL,
            provider_user_id INTEGER NOT NULL,
            quote_id TEXT NOT NULL,
            amount_cents INTEGER NOT NULL CHECK(amount_cents > 0),
            method TEXT NOT NULL CHECK(method IN ('card','cash')),
            scenario TEXT NOT NULL,
            status TEXT NOT NULL CHECK(status IN ('test_approved','declined','temporary_failure','awaiting_cash','cash_received')),
            idempotency_key TEXT NOT NULL,
            reference TEXT NOT NULL UNIQUE,
            attempted_at TEXT NOT NULL,
            received_at TEXT NULL
        )");
        $db = $this->fixtureDir.DIRECTORY_SEPARATOR.'tekora.sqlite';
        $pdo = DB::connection('sqlite')->getPdo();
        $pdo->exec('VACUUM main INTO '.$pdo->quote($db));
        file_put_contents($this->fixtureDir.DIRECTORY_SEPARATOR.'.tekora-b32-synthetic', "TEST ONLY\n");
        DB::purge('sqlite');
        config(['database.connections.sqlite.database' => $db]);
        $this->withServerVariables(['HTTP_HOST' => 'localhost', 'REMOTE_ADDR' => '127.0.0.1']);
    }

    protected function tearDown(): void
    {
        DB::purge('sqlite');
        parent::tearDown();

        if ($this->fixtureDir === null) {
            return;
        }

        // Remove only the brand-new, exclusively owned temporary test files.
        foreach (['tekora.sqlite', 'tekora.sqlite-wal', 'tekora.sqlite-shm', '.tekora-b32-synthetic'] as $name) {
            $path = $this->fixtureDir.DIRECTORY_SEPARATOR.$name;

            if (is_file($path) && is_link($path) === false) {
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
            ->assertOk()->assertSee('Checkout de prueba')->assertSee('$1,000.00')
            ->assertSee('co-card-position', false)
            ->assertSee('b32-method-surface', false)
            ->assertSee('checkout-integrated', false);
        $this->post(route('checkout.local.submit', $reservation->id), [
            'method' => 'card', 'card_number' => '4242424242424242',
            'expiry' => '12/30', 'cvv' => '123', 'idempotency_key' => (string) Str::uuid(),
        ])->assertRedirect(route('checkout.local.show', $reservation->id));
        $this->get(route('checkout.local.show', $reservation->id))
            ->assertOk()->assertSee('Tarjeta de prueba aprobada')
            ->assertSee('Reserva pendiente de confirmación')
            ->assertDontSee('¡Pago realizado con éxito!')
            ->assertSee('COMPROBANTE INTERNO')
            ->assertSee('Confirmar la reserva es el siguiente paso.');
        $this->assertSame('test_approved', app(LocalCheckoutAttemptStore::class)->current($reservation->id)['status']);
        $this->assertSame('held', $reservation->fresh()->effectiveStatus()->value);
        $this->actingAs($provider, 'web')->get(route('checkout.local.show', $reservation->id))->assertNotFound();
    }

    public function test_provider_receives_cash_and_client_cannot_use_provider_command(): void
    {
        [$client, $provider, $reservation] = $this->held();
        $checkout = $this->actingAs($client, 'web')->get(route('checkout.local.show', $reservation->id))->assertOk();
        $document = new DOMDocument;
        $document->loadHTML($checkout->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
        $form = (new DOMXPath($document))->query('//form[@data-b32-cash-fallback]')->item(0);
        $this->assertInstanceOf(DOMElement::class, $form);
        $this->assertSame(route('checkout.local.submit', $reservation->id), $form->getAttribute('action'));
        $fields = [];
        foreach ($form->getElementsByTagName('input') as $field) {
            $fields[$field->getAttribute('name')] = $field->getAttribute('value');
        }
        $this->assertEqualsCanonicalizing(['_token', 'method', 'idempotency_key'], array_keys($fields));
        $this->assertSame('cash', $fields['method']);
        $this->post($form->getAttribute('action'), $fields)->assertRedirect();
        $this->post(route('checkout.local.cash.receive', $reservation->id))->assertForbidden();
        $this->actingAs($provider, 'web')->get(route('checkout.local.cash', $reservation->id))
            ->assertOk()->assertSee('Pendiente de recepción')
            ->assertSee('co-receiver-panel', false)
            ->assertSee('COMPROBANTE INTERNO');
        $this->post(route('checkout.local.cash.receive', $reservation->id))->assertRedirect();
        $this->actingAs($client, 'web')->get(route('checkout.local.show', $reservation->id))
            ->assertOk()->assertSee('Efectivo recibido por el prestador');
        $this->assertSame('held', $reservation->fresh()->effectiveStatus()->value);
    }

    public function test_only_the_owner_sees_the_integrated_checkout_link_and_provider_sees_cash_receipt(): void
    {
        [$client, $provider, $reservation] = $this->held();
        $inquiryUrl = route('inquiries.show', $reservation->service_inquiry_id);
        $clientPage = $this->actingAs($client, 'web')->get($inquiryUrl)->assertOk()
            ->assertSee('Elegir método de pago de prueba')
            ->assertSee('data-journey-next="choose_test_checkout"', false)
            ->assertDontSee('Explorar el pago (DEMO)');
        $this->assertStringContainsString(
            'href="'.route('checkout.local.show', $reservation->id).'"',
            $clientPage->getContent()
        );
        $this->assertStringContainsString('referrerpolicy="no-referrer"', $clientPage->getContent());
        // A held booking in the disposable B3.2 fixture must offer checkout
        // first; the final commit form appears only after a valid synthetic choice.
        $clientPage->assertSee('Antes de confirmar definitivamente tu reserva')
            ->assertDontSee('data-hold-confirm', false);

        $this->actingAs($provider, 'web')->get($inquiryUrl)->assertOk()
            ->assertSee('Consultar recepción de efectivo')
            ->assertDontSee('Elegir método de pago');
        $this->actingAs($this->account(\App\Enums\RoleName::Client), 'web')
            ->get($inquiryUrl)->assertForbidden();
    }

    public function test_disposable_checkout_requires_accepted_payment_choice_before_booking_commit(): void
    {
        [$client, , $reservation] = $this->held();
        $this->actingAs($client, 'web');
        $version = $reservation->fresh()->version;

        $this->get(route('reservations.show', $reservation->id))->assertOk()
            ->assertSee('Antes de confirmar definitivamente tu reserva')
            ->assertSee('Elegir método de pago de prueba')
            ->assertDontSee('data-hold-confirm', false);

        $this->patch(route('reservations.commit', $reservation->id), [
            'version' => $version, 'idempotency_key' => (string) Str::uuid(),
        ])->assertStatus(409)->assertSee('Primero elige y registra un método de pago');
        $this->assertSame('held', $reservation->fresh()->effectiveStatus()->value);
        $this->assertSame($version, $reservation->fresh()->version);

        $this->post(route('checkout.local.submit', $reservation->id), [
            'method' => 'card', 'card_number' => '4000000000000002',
            'expiry' => '12/30', 'cvv' => '123', 'idempotency_key' => (string) Str::uuid(),
        ])->assertRedirect();
        $this->patch(route('reservations.commit', $reservation->id), [
            'version' => $version, 'idempotency_key' => (string) Str::uuid(),
        ])->assertStatus(409);
        $this->assertSame('held', $reservation->fresh()->effectiveStatus()->value);

        $this->post(route('checkout.local.submit', $reservation->id), [
            'method' => 'card', 'card_number' => '4242424242424242',
            'expiry' => '12/30', 'cvv' => '123', 'idempotency_key' => (string) Str::uuid(),
        ])->assertRedirect();
        $this->get(route('reservations.show', $reservation->id))->assertOk()
            ->assertSee('data-hold-confirm', false)
            ->assertDontSee('Antes de confirmar definitivamente tu reserva');
        $this->get(route('inquiries.show', $reservation->service_inquiry_id))->assertOk()
            ->assertSee('data-journey-next="confirm_reservation"', false)
            ->assertSee('data-hold-confirm', false);

        $this->patch(route('reservations.commit', $reservation->id), [
            'version' => $version, 'idempotency_key' => (string) Str::uuid(),
        ])->assertRedirect();
        $this->assertSame('confirmed', $reservation->fresh()->status->value);
        $this->get(route('checkout.local.show', $reservation->id))->assertOk()
            ->assertSee('Reserva confirmada')
            ->assertDontSee('Confirmar la reserva es el siguiente paso.')
            ->assertDontSee('data-b32-form', false);
    }

    public function test_disposable_cash_selection_allows_booking_then_provider_receives_cash(): void
    {
        [$client, $provider, $reservation] = $this->held();
        $this->actingAs($client, 'web')->post(route('checkout.local.submit', $reservation->id), [
            'method' => 'cash', 'idempotency_key' => (string) Str::uuid(),
        ])->assertRedirect();

        $this->patch(route('reservations.commit', $reservation->id), [
            'version' => $reservation->fresh()->version,
            'idempotency_key' => (string) Str::uuid(),
        ])->assertRedirect();
        $this->assertSame('confirmed', $reservation->fresh()->status->value);

        $this->travel(900)->seconds();
        $this->get(route('checkout.local.show', $reservation))->assertOk()
            ->assertSee('El prestador podrá registrar la recepción del efectivo desde su cuenta.');

        $this->actingAs($provider, 'web')->get(route('checkout.local.cash', $reservation->id))
            ->assertOk()->assertSee('Pendiente de recepción');
        $this->post(route('checkout.local.cash.receive', $reservation->id))->assertRedirect();
        $this->assertSame('cash_received', app(LocalCheckoutAttemptStore::class)->current($reservation->id)['status']);
        $this->assertSame('confirmed', $reservation->fresh()->status->value);
    }

    public function test_provider_views_show_bound_card_status_and_internal_reference_after_mobile_style_checkout(): void
    {
        [$client, $provider, $reservation] = $this->held();
        $booking = route('reservations.show', $reservation->id);
        $inquiry = route('inquiries.show', $reservation->service_inquiry_id);

        $this->actingAs($provider, 'web')->get($booking)->assertOk()
            ->assertSee('Estado de pago de prueba')
            ->assertSee('Sin método de prueba seleccionado');
        $this->actingAs($client, 'web')->post(route('checkout.local.submit', $reservation->id), [
            'method' => 'card', 'card_number' => '4242424242424242',
            'expiry' => '12/30', 'cvv' => '123', 'idempotency_key' => (string) Str::uuid(),
        ])->assertRedirect();
        $ref = app(LocalCheckoutAttemptStore::class)->current($reservation->id)['reference'];

        $this->actingAs($provider, 'web')->get($booking)->assertOk()
            ->assertSee('Tarjeta aprobada (prueba)')
            ->assertSee($ref)
            ->assertDontSee('4242424242424242');
        $this->actingAs($client, 'web')->patch(route('reservations.commit', $reservation->id), [
            'version' => $reservation->fresh()->version, 'idempotency_key' => (string) Str::uuid(),
        ])->assertRedirect();
        $this->actingAs($provider, 'web')->get($booking)->assertOk()
            ->assertSee('Pagado (prueba)')
            ->assertSee($ref);
        $this->get($inquiry)->assertOk()
            ->assertSee('Estado de pago de prueba')
            ->assertSee('Pagado (prueba)')
            ->assertSee($ref);
        $this->actingAs($client, 'web')->get($booking)->assertOk()
            ->assertDontSee('data-b32-provider-payment', false);
        $this->actingAs($this->account(\App\Enums\RoleName::Client), 'web')
            ->get($booking)->assertForbidden();
    }

    public function test_cancellation_keeps_historical_receipt_without_claiming_active_payment_or_refund(): void
    {
        [$client, $provider, $reservation] = $this->held();
        $this->actingAs($client, 'web')->post(route('checkout.local.submit', $reservation->id), [
            'method' => 'card', 'card_number' => '4242424242424242',
            'expiry' => '12/30', 'cvv' => '123', 'idempotency_key' => (string) Str::uuid(),
        ])->assertRedirect();
        $this->patch(route('reservations.commit', $reservation->id), [
            'version' => $reservation->fresh()->version, 'idempotency_key' => (string) Str::uuid(),
        ])->assertRedirect();
        $this->patch(route('reservations.cancel', $reservation->id), [
            'version' => $reservation->fresh()->version, 'idempotency_key' => (string) Str::uuid(),
        ])->assertRedirect();
        $this->assertSame('cancelled', $reservation->fresh()->status->value);
        $this->actingAs($provider, 'web')->get(route('reservations.show', $reservation->id))->assertOk()
            ->assertSee('Aprobación de prueba histórica')
            ->assertSee('no implica devolución, reembolso ni liquidación')
            ->assertDontSee('>Pagado (prueba)<', false);
        $this->assertSame('test_approved', app(LocalCheckoutAttemptStore::class)->current($reservation->id)['status']);
    }

    public function test_provider_sees_cash_pending_but_not_as_received(): void
    {
        [$client, $provider, $reservation] = $this->held();
        $this->actingAs($client, 'web')->post(route('checkout.local.submit', $reservation->id), [
            'method' => 'cash', 'idempotency_key' => (string) Str::uuid(),
        ])->assertRedirect();
        $this->patch(route('reservations.commit', $reservation->id), [
            'version' => $reservation->fresh()->version, 'idempotency_key' => (string) Str::uuid(),
        ])->assertRedirect();

        $this->actingAs($provider, 'web')->get(route('inquiries.show', $reservation->service_inquiry_id))->assertOk()
            ->assertSee('Efectivo pendiente')
            ->assertDontSee('>Pagado (prueba)<', false);
        $this->get(route('reservations.show', $reservation->id))->assertOk()
            ->assertSee('Consultar recepción de efectivo');
        $this->post(route('checkout.local.cash.receive', $reservation->id))->assertRedirect();
        $this->get(route('reservations.show', $reservation->id))->assertOk()
            ->assertSee('Efectivo recibido (prueba)')
            ->assertDontSee('>Pagado (prueba)<', false);
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
        $this->get(route('checkout.local.show', $reservation->id))->assertOk()
            ->assertSee('Retención vencida')
            ->assertDontSee('data-b32-form', false);
    }

    public function test_expired_pending_cash_keeps_history_without_an_unavailable_action(): void
    {
        [$client, $provider, $reservation] = $this->held();
        $this->actingAs($client, 'web')->post(route('checkout.local.submit', $reservation), [
            'method' => 'cash', 'idempotency_key' => (string) Str::uuid(),
        ])->assertRedirect();
        $this->get(route('checkout.local.show', $reservation))->assertOk()
            ->assertSee('El prestador podrá registrar la recepción del efectivo desde su cuenta.');
        $this->actingAs($provider, 'web')->get(route('checkout.local.cash', $reservation))->assertOk()
            ->assertSee('Registrar recepción de efectivo');
        $before = app(LocalCheckoutAttemptStore::class)->current($reservation->id);
        $this->travel(900)->seconds();
        $this->get(route('checkout.local.cash', $reservation))->assertOk()
            ->assertSee('Pendiente de recepción')
            ->assertSee('El estado del horario impide registrar la recepción')
            ->assertDontSee('Registrar recepción de efectivo');
        $this->post(route('checkout.local.cash.receive', $reservation))->assertConflict();
        $this->actingAs($client, 'web')->get(route('checkout.local.show', $reservation))->assertOk()
            ->assertSee('Retención vencida')
            ->assertSee('La selección de efectivo se conserva en el historial de la reserva. La recepción no quedó registrada.')
            ->assertSee($before['reference'])
            ->assertDontSee('El prestador podrá registrar la recepción del efectivo desde su cuenta.');
        $this->assertSame($before, app(LocalCheckoutAttemptStore::class)->current($reservation->id));
        $this->assertHoldIntegrity();
    }

    public static function cancelledCashBookings(): array
    {
        return [
            'hold released before confirmation' => [false, 'Retención liberada'],
            'confirmed booking cancelled' => [true, 'Reserva cancelada'],
        ];
    }

    #[DataProvider('cancelledCashBookings')]
    public function test_cancelled_pending_cash_is_shown_as_history_to_the_client(bool $confirmed, string $bookingStatus): void
    {
        [$client, $provider, $reservation] = $this->held();
        $this->actingAs($client, 'web')->post(route('checkout.local.submit', $reservation), [
            'method' => 'cash', 'idempotency_key' => (string) Str::uuid(),
        ])->assertRedirect();
        if ($confirmed) {
            $this->patch(route('reservations.commit', $reservation), $this->holdInput())->assertRedirect();
        }
        $this->patch(route('reservations.cancel', $reservation), $this->holdInput([
            'version' => $reservation->fresh()->version,
        ]))->assertRedirect();

        $this->get(route('checkout.local.show', $reservation))->assertOk()
            ->assertSee($bookingStatus)
            ->assertSee('La selección de efectivo se conserva en el historial de la reserva. La recepción no quedó registrada.')
            ->assertDontSee('El prestador podrá registrar la recepción del efectivo desde su cuenta.');
        $this->actingAs($provider, 'web')->post(route('checkout.local.cash.receive', $reservation))->assertConflict();
    }
}
