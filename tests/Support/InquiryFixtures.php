<?php

namespace Tests\Support;

use App\Enums\InquiryAction;
use App\Enums\InquiryStatus;
use App\Enums\RoleName;
use App\Models\ServiceInquiry;
use App\Models\User;
use App\Services\InquiryWorkflow;

trait InquiryFixtures
{
    use CatalogFixtures;

    protected function inquiry(?User $client = null, ?User $provider = null, InquiryStatus $status = InquiryStatus::Pending): ServiceInquiry
    {
        $client ??= $this->account(RoleName::Client);
        $provider ??= $this->account(RoleName::Provider);
        $service = $this->service($provider);
        $workflow = app(InquiryWorkflow::class);
        $inquiry = $workflow->open($client, $service->id, 'Necesito ayuda para revisar mi equipo de trabajo.')['inquiry'];
        if (in_array($status, [InquiryStatus::Accepted, InquiryStatus::Completed], true)) $workflow->decide($provider, $inquiry->id, InquiryAction::Accept);
        if ($status === InquiryStatus::Completed) $workflow->decide($provider, $inquiry->id, InquiryAction::Complete);
        if ($status === InquiryStatus::Rejected) $workflow->decide($provider, $inquiry->id, InquiryAction::Reject);
        if ($status === InquiryStatus::Cancelled) $workflow->decide($client, $inquiry->id, InquiryAction::Cancel);

        return $inquiry->fresh();
    }
}
