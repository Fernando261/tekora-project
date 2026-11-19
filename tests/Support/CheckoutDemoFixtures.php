<?php

namespace Tests\Support;

use App\Demo\CheckoutStore;

trait CheckoutDemoFixtures
{
    private string $demoRoot;

    private string $operatorToken;

    private function prepareDemo(): void
    {
        $this->withCredentials();
        $this->demoRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'tekora-checkout-demo-'.bin2hex(random_bytes(8));
        $this->operatorToken = CheckoutStore::prepare($this->demoRoot);
        config(['checkout_demo.root' => $this->demoRoot]);
        $this->beforeApplicationDestroyed(function () {
            foreach (glob($this->demoRoot.'/contexts/*') as $path) {
                unlink($path);
            }
            rmdir($this->demoRoot.'/contexts');
            unlink($this->demoRoot.'/runtime.json');
            rmdir($this->demoRoot);
        });
    }

    private function customer(): array
    {
        $response = $this->get('/demo/checkout')->assertOk();
        $cookie = $response->getCookie('tekora_checkout_client')->getValue();
        $this->withCookie('tekora_checkout_client', $cookie);

        return [$response->viewData('demo'), $cookie];
    }

    private function receiver(): string
    {
        $response = $this->get('/demo/checkout/provider/access?token='.$this->operatorToken)->assertRedirect('/demo/checkout/provider');
        $this->withCookie('tekora_checkout_operator', $response->getCookie('tekora_checkout_operator')->getValue());

        return hash_hmac('sha256', 'provider-demo-csrf', $this->operatorToken);
    }

    private function demoPost(string $path, array $body, string $csrf)
    {
        return $this->postJson('/demo/checkout/'.$path, $body, ['X-Checkout-Demo-CSRF' => $csrf]);
    }
}
