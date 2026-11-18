<?php

namespace App\Http\Controllers;

use App\Payments\CommercialAmount;
use Illuminate\Http\Response;

final class CheckoutDemoController extends Controller
{
    public function __invoke(): Response
    {
        abort_unless(app()->environment(['local', 'testing']), 404);

        // Presentation fixture only: no IDs, database reads, quote acceptance or booking commands.
        return response()->view('demo.checkout', [
            'amount' => CommercialAmount::fromCents(100000),
            'exampleDate' => now('America/Mexico_City')->addDays(7)->format('d/m/Y'),
        ])->withHeaders([
            'Cache-Control' => 'no-store, private',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Permissions-Policy' => 'camera=(), microphone=(), payment=()',
            // Built, same-origin assets only. The simulator cannot connect or submit a form.
            'Content-Security-Policy' => "default-src 'none'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'none'; form-action 'none'; base-uri 'none'; object-src 'none'; frame-ancestors 'none'",
        ]);
    }
}
