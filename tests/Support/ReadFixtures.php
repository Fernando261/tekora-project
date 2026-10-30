<?php

namespace Tests\Support;

use App\Models\ServiceInquiry;
use App\Models\User;
use App\Services\InquiryReadTracking;
use Illuminate\Support\Collection;

trait ReadFixtures
{
    use MessageFixtures;

    protected function readToken(User $user, ServiceInquiry $inquiry, ?Collection $page = null): string
    {
        return app(InquiryReadTracking::class)->tokenFor($user, $inquiry,
            $page ?? $inquiry->messages()->get(['id', 'author_user_id'])) ?? throw new \LogicException('El fixture necesita recibidos sin leer.');
    }
}
