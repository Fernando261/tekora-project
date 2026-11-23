<?php

namespace App\Services;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use SensitiveParameter;

final class MobileRegistrationProof
{
    public function issue(string $email): string
    {
        return Crypt::encryptString(json_encode(['purpose' => 'mobile-registration', 'email' => $email,
            'expires_at' => now()->addMinutes(30)->timestamp], JSON_THROW_ON_ERROR));
    }

    public function matches(#[SensitiveParameter] string $token, string $email): bool
    {
        try {
            $proof = json_decode(Crypt::decryptString($token), true);
        } catch (DecryptException) {
            return false;
        }

        return is_array($proof) && ($proof['purpose'] ?? null) === 'mobile-registration'
            && ($proof['email'] ?? null) === $email && is_int($proof['expires_at'] ?? null)
            && $proof['expires_at'] > now()->timestamp;
    }
}
