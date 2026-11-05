<?php

namespace Tests\Support;

use App\Models\User;
use Illuminate\Support\Facades\Auth;

trait MobileOperationsFixtures
{
    use ReservationFixtures;

    private array $mobileTokens = [];

    protected function bearer(User $user): array
    {
        $this->mobileTokens[$user->id] ??= $user->createToken('mobile:Feature', ['client:read'], now()->addDays(30))->plainTextToken;
        Auth::forgetGuards();

        return ['Authorization' => 'Bearer '.$this->mobileTokens[$user->id]];
    }
}
