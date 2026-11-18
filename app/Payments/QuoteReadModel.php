<?php

namespace App\Payments;

use App\Models\User;
use DateTimeImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Read side over the existing quote history; all writes stay in SyntheticQuotes. */
final class QuoteReadModel
{
    public function __construct(private readonly PricingStore $store, private readonly SyntheticQuotes $quotes) {}

    public function forInquiry(User $actor, int $inquiry, int $perPage = 12, int $page = 1): LengthAwarePaginator
    {
        return DB::transaction(function () use ($actor, $inquiry, $perPage, $page) {
            $this->quotes->ownedInquiry($actor, $inquiry);
            $now = now('UTC')->toDateTimeImmutable();

            return DB::table('service_quotes')->where('client_user_id', $actor->id)->where('service_inquiry_id', $inquiry)
                ->orderByDesc('issued_at_utc')->orderByDesc('id')->paginate($perPage, ['id'], 'page', $page)
                ->through(fn ($row) => $this->snapshot($this->quotes->view($actor, $row->id), $now));
        }, 3);
    }

    public function detail(User $actor, string $quote): QuoteSnapshot
    {
        return DB::transaction(fn () => $this->snapshot($this->quotes->view($actor, strtolower($quote)), now('UTC')->toDateTimeImmutable()), 3);
    }

    public function snapshot(SyntheticQuote $quote, DateTimeImmutable $now): QuoteSnapshot
    {
        $acceptance = $this->store->acceptance($quote->id);
        if ($acceptance && ($acceptance->clientId !== $quote->clientId || $acceptance->quoteId !== $quote->id
            || $acceptance->acceptedAt < $quote->issuedAt || $acceptance->acceptedAt >= $quote->expiresAt)) {
            throw new LogicException('La aceptación histórica no coincide con su cotización.');
        }

        return new QuoteSnapshot($quote, $acceptance, $now);
    }
}
