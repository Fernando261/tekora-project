<?php

namespace App\Console\Commands;

use App\Demo\CheckoutStore;
use Illuminate\Console\Command;

final class PrepareCheckoutDemo extends Command
{
    protected $signature = 'demo:checkout-prepare {directory : New absolute private directory} {--url=http://127.0.0.1:8097}';

    protected $description = 'Prepare a new synthetic checkout runtime; never opens an application database';

    public function handle(): int
    {
        abort_unless(app()->environment(['local', 'testing']), 404);
        $url = rtrim($this->option('url'), '/');
        if (! preg_match('~^https?://(127\.0\.0\.1|localhost)(:[0-9]+)?$~D', $url)) {
            $this->error('Use una URL local.');

            return self::FAILURE;
        }
        $token = CheckoutStore::prepare($this->argument('directory'));
        $this->info('Entorno privado nuevo preparado. Configure TEKORA_CHECKOUT_DEMO_ROOT con esa ruta en este proceso local.');
        $this->line('Cliente: '.$url.'/demo/checkout');
        $this->warn('Acceso exclusivo del presentador al prestador demo; no compartir ni guardar en artefactos:');
        $this->line($url.'/demo/checkout/provider/access?token='.$token);

        return self::SUCCESS;
    }
}
