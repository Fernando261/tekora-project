<?php

namespace Tests\Feature;

use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CheckoutDemoTest extends TestCase
{
    public function test_demo_renders_without_authentication_or_database_queries(): void
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $this->get(route('demo.checkout'))->assertOk()->assertHeaderMissing('Set-Cookie')->assertSee('Una demostración. Ningún cobro.')
            ->assertSee('No escribas datos bancarios.');
        $this->assertSame([], $queries);
    }

    public function test_total_contains_the_commission_instead_of_adding_it_again(): void
    {
        $this->get(route('demo.checkout'))->assertOk()
            ->assertSee('$1,000.00 MXN')->assertSee('$850.00 MXN')->assertSee('$150.00 MXN')
            ->assertSee('Comisión Tekora · 15 % incluida')->assertDontSee('$1,150.00')
            ->assertSee('SIN VÍNCULO A UNA RESERVA');
    }

    public function test_controls_cannot_accept_free_text_card_numbers_or_security_codes(): void
    {
        $html = $this->get(route('demo.checkout'))->assertOk()->getContent();
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new DOMXPath($document);

        $this->assertSame(0, $xpath->query('//input|//textarea|//form|//*[@contenteditable]')->length);
        $this->assertSame(5, $xpath->query('//select[@data-demo-select]')->length);
        foreach ($xpath->query('//select') as $select) {
            $this->assertFalse($select->hasAttribute('name'));
            foreach ($select->getElementsByTagName('option') as $option) {
                $this->assertMatchesRegularExpression('/^[a-z]+$/D', $option->getAttribute('value'));
            }
        }
        foreach ($xpath->query('//button') as $button) {
            $this->assertSame('button', $button->getAttribute('type'));
        }
        $this->assertSame(1, $xpath->query('//fieldset[@data-demo-fields and @disabled]')->length);
        $this->assertStringContainsString('Simulación completada', $html);
        $this->assertStringNotContainsString('autocomplete="cc-', $html);
        $this->assertStringNotContainsString('Pago realizado', $html);
    }

    public function test_security_headers_block_transmission_submission_and_embedding(): void
    {
        $response = $this->get(route('demo.checkout'))->assertOk();
        foreach (["connect-src 'none'", "form-action 'none'", "frame-ancestors 'none'", "script-src 'self'"] as $directive) {
            $this->assertStringContainsString($directive, $response->headers->get('Content-Security-Policy'));
        }
        $response->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Frame-Options', 'DENY')->assertHeader('Permissions-Policy', 'camera=(), microphone=(), payment=()');
    }

    public static function unavailableEnvironments(): array
    {
        return ['production' => ['production'], 'staging' => ['staging']];
    }

    #[DataProvider('unavailableEnvironments')]
    public function test_demo_is_unavailable_outside_local_and_testing(string $environment): void
    {
        $this->app->instance('env', $environment);
        try {
            $this->get(route('demo.checkout'))->assertNotFound();
        } finally {
            $this->app->instance('env', 'testing');
        }
    }

    public function test_demo_has_no_write_route_and_ignores_external_business_context(): void
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });
        $this->post('/demo/checkout')->assertStatus(405);
        $this->patch('/demo/checkout')->assertStatus(405);
        $this->get('/demo/checkout?reservation_id=999&quote_id=UNTRUSTED_DEMO_TOKEN&amount_cents=1')->assertOk()
            ->assertSee('$1,000.00 MXN')->assertDontSee('UNTRUSTED_DEMO_TOKEN')->assertDontSee('reserva #999');
        $this->assertSame([], $queries, 'La ruta de presentación no debe consultar ni mutar datos de negocio.');
    }
}
