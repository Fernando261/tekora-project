<?php

namespace App\Payments;

use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

final class CreatePricedService
{
    public function __construct(private readonly PricingStore $store, private readonly ServicePricing $pricing) {}

    public function create(User $actor, array $details, int $cents, string $key): Service
    {
        $key = PricingInput::key(['idempotency_key' => $key]);
        CommercialAmount::fromCents($cents);
        ksort($details);
        $fingerprint = hash('sha256', json_encode([$details, $cents], JSON_THROW_ON_ERROR));

        return $this->store->atomic(function () use ($actor, $details, $cents, $key, $fingerprint) {
            Gate::forUser($actor)->authorize('create', Service::class);
            $previous = DB::table('service_pricing_creations')->where('actor_id', $actor->id)->where('idempotency_key', $key)->first();
            if ($previous) {
                if (! hash_equals($previous->fingerprint, $fingerprint)) {
                    throw new PricingConflict('La clave ya identifica otra creación. Abre de nuevo el formulario.');
                }
                $service = Service::findOrFail($previous->service_id);
                Gate::forUser($actor)->authorize('update', $service);

                return $service;
            }
            $service = $actor->providerProfile()->firstOrFail()->services()->create($details);
            $price = $this->pricing->publish($actor, $service->id, ['amount_cents' => $cents, 'expected_version' => 0, 'idempotency_key' => (string) Str::uuid()]);
            DB::table('service_pricing_creations')->insert(['actor_id' => $actor->id, 'idempotency_key' => $key, 'service_id' => $service->id, 'price_version_id' => $price->id, 'fingerprint' => $fingerprint]);

            return $service;
        });
    }
}
