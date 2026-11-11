<?php

namespace App\Payments;

use App\Enums\InquiryStatus;
use App\Enums\Permission;
use App\Models\Service;
use App\Models\ServiceInquiry;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class SyntheticQuotes
{
    // Fixture default only. Quote validity is NOT the B2 15-minute slot hold.
    public const DEFAULT_LIFETIME_SECONDS = 1800;

    public function __construct(private readonly PricingStore $store, private readonly int $lifetimeSeconds = self::DEFAULT_LIFETIME_SECONDS)
    {
        if ($lifetimeSeconds < 1 || $lifetimeSeconds > 86400) {
            throw new InvalidArgumentException('La vigencia sintética requiere entre 1 y 86400 segundos.');
        }
    }

    public function issue(User $actor, int $inquiryId, array $input): SyntheticQuote
    {
        $this->ownedInquiry($actor, $inquiryId);
        PricingInput::only($input, ['idempotency_key']);
        $key = PricingInput::key($input);

        return $this->store->atomic(function () use ($actor, $inquiryId, $key) {
            $inquiry = $this->ownedInquiry($actor, $inquiryId);
            $existing = $this->store->quoteByKey($actor->id, $key);
            if ($existing) {
                if ($existing->inquiryId !== $inquiryId) {
                    throw new PricingConflict('La clave ya pertenece a otra solicitud.');
                }

                return $existing;
            }
            if ($inquiry->status !== InquiryStatus::Accepted) {
                throw new PricingConflict('La cotización sintética requiere una solicitud aceptada.');
            }
            $service = Service::publiclyVisible()->findOrFail($inquiry->service_id);
            Gate::forUser($actor)->authorize('create', [ServiceInquiry::class, $service]);
            $price = $this->store->latestPrice($service->id);
            if (! $price) {
                throw new PricingConflict('El servicio todavía no tiene un precio estructurado.');
            }
            $issuedAt = now('UTC')->toDateTimeImmutable();
            $quote = new SyntheticQuote((string) Str::uuid(), $inquiry->id, $service->id,
                $service->provider_profile_id, $actor->id, $price, $key, $issuedAt,
                $issuedAt->modify('+'.$this->lifetimeSeconds.' seconds'));
            $this->store->appendQuote($quote);

            return $quote;
        });
    }

    public function view(User $actor, string $quoteId): SyntheticQuote
    {
        $quote = $this->store->quote($quoteId);
        abort_unless($quote, 404);
        abort_unless($quote->clientId === $actor->id, 403);
        $this->ownedInquiry($actor, $quote->inquiryId);

        return $quote;
    }

    public function accept(User $actor, string $quoteId, array $input = []): QuoteAcceptance
    {
        $this->view($actor, $quoteId);
        PricingInput::only($input);

        return $this->store->atomic(function () use ($actor, $quoteId) {
            $quote = $this->view($actor, $quoteId);
            $existing = $this->store->acceptance($quoteId);
            if ($existing) {
                return $existing;
            }
            $now = now('UTC')->toDateTimeImmutable();
            if ($now >= $quote->expiresAt) {
                throw new PricingConflict('La cotización venció; solicita una nueva sin alterar la anterior.');
            }
            $inquiry = $this->ownedInquiry($actor, $quote->inquiryId);
            if ($inquiry->status !== InquiryStatus::Accepted) {
                throw new PricingConflict('La solicitud ya no admite una nueva aceptación.');
            }
            $service = Service::publiclyVisible()->findOrFail($quote->serviceId);
            Gate::forUser($actor)->authorize('create', [ServiceInquiry::class, $service]);
            $acceptance = new QuoteAcceptance($quote->id, $actor->id, $now);
            $this->store->appendAcceptance($acceptance);

            return $acceptance;
        });
    }

    private function ownedInquiry(User $actor, int $inquiryId): ServiceInquiry
    {
        Gate::forUser($actor)->authorize(Permission::ManageOwnInquiries->value);
        $inquiry = ServiceInquiry::where('client_user_id', $actor->id)->findOrFail($inquiryId);
        Gate::forUser($actor)->authorize('view', $inquiry);

        return $inquiry;
    }
}
