<?php

namespace Tests\Support;

use App\Enums\InquiryStatus;
use App\Enums\RoleName;
use App\Models\User;
use App\Payments\ServicePricing;
use App\Payments\SyntheticQuotes;
use App\Services\ReservationWorkflow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

trait SlotHoldFixtures
{
    use MobileOperationsFixtures;

    protected function pricedProposal(?User $client = null, ?User $provider = null, array $changes = []): array
    {
        if (! $client && ! $provider) {
            [$client, $provider, $inquiry] = $this->reservationContext();
        } else {
            $client ??= $this->account(RoleName::Client);
            $provider ??= $this->account(RoleName::Provider);
            $inquiry = $this->inquiry($client, $provider, InquiryStatus::Accepted);
            if (! $provider->providerProfile->availabilitySetting()->exists()) {
                $this->publishSchedule($provider);
            }
        }
        app(ServicePricing::class)->publish($provider, $inquiry->service_id, ['amount_cents' => 100000, 'expected_version' => 0, 'idempotency_key' => (string) Str::uuid()]);
        $quote = app(SyntheticQuotes::class)->issue($client, $inquiry->id, ['idempotency_key' => (string) Str::uuid()]);
        app(SyntheticQuotes::class)->accept($client, $quote->id);
        $input = $this->reservationData(['quote_id' => $quote->id] + $changes);
        $reservation = app(ReservationWorkflow::class)->propose($client, $inquiry->id, $input)['reservation'];

        return [$client, $provider, $inquiry, $quote, $reservation, $input];
    }

    protected function holdInput(array $changes = []): array
    {
        return $changes + ['version' => 1, 'idempotency_key' => (string) Str::uuid()];
    }

    protected function assertHoldIntegrity(): void
    {
        $this->assertSame(1, (int) DB::selectOne('PRAGMA foreign_keys')->foreign_keys);
        $this->assertSame('ok', DB::selectOne('PRAGMA integrity_check')->integrity_check);
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
        $this->assertSame(0, (int) DB::selectOne('SELECT count(*) AS n FROM reservation_occupancy a JOIN reservation_occupancy b ON a.reservation_id<b.reservation_id AND a.provider_profile_id=b.provider_profile_id AND a.starts_at_utc<b.ends_at_utc AND a.ends_at_utc>b.starts_at_utc')->n);
    }
}
