<?php

namespace App\Payments;

use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

final class ServicePricing
{
    public function __construct(private readonly PricingStore $store) {}

    public function publish(User $actor, int $serviceId, array $input): PriceVersion
    {
        $service = Service::findOrFail($serviceId);
        Gate::forUser($actor)->authorize('update', $service);
        PricingInput::only($input, ['amount_cents', 'expected_version', 'idempotency_key']);
        $amount = PricingInput::amount($input);
        $version = PricingInput::version($input);
        $key = PricingInput::key($input);

        return $this->store->atomic(function () use ($actor, $serviceId, $amount, $version, $key) {
            $service = Service::findOrFail($serviceId);
            Gate::forUser($actor)->authorize('update', $service);
            $existing = $this->store->priceByKey($serviceId, $key);
            if ($existing) {
                if ($existing->createdBy !== $actor->id || $existing->amount->totalCents !== $amount->totalCents || $existing->version !== $version + 1) {
                    throw new PricingConflict('La clave ya pertenece a otra modificación del precio.');
                }

                return $existing;
            }
            if (($this->store->latestPrice($serviceId)?->version ?? 0) !== $version) {
                throw new PricingConflict('El precio cambió. Consulta su versión antes de preparar otra modificación.');
            }

            $price = new PriceVersion((string) Str::uuid(), $service->id, $service->provider_profile_id,
                $version + 1, $amount, $actor->id, $key, now('UTC')->toDateTimeImmutable());
            $this->store->appendPrice($price);

            return $price;
        });
    }
}
