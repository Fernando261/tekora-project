<?php

namespace App\Services;

use App\Enums\RoleName;
use App\Models\User;
use App\Support\LegalPolicies;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use SensitiveParameter;

final class AccountRegistration
{
    public function __construct(private readonly RoleMemberships $memberships) {}

    public function create(#[SensitiveParameter] array $input, bool $provider = false, bool $emailVerified = false): ?User
    {
        $email = Str::lower($input['email']);
        try {
            return DB::transaction(function () use ($input, $email, $provider, $emailVerified) {
                if (User::whereRaw('LOWER(email) = ?', [$email])->lockForUpdate()->first()) {
                    return null;
                }

                $user = new User(Arr::only($input, ['name', 'age', 'gender', 'email']));
                $user->password = Hash::make($input['password']);
                if ($emailVerified) {
                    $user->email_verified_at = now();
                }
                $user->save();
                $this->memberships->enroll($user, RoleName::Client);
                if ($provider) {
                    $this->memberships->enroll($user, RoleName::Provider);
                    app(ProfessionalCredentials::class)->enroll($user, $input);
                }

                if (($input['terms_version'] ?? null) === LegalPolicies::VERSION && isset($input['accept_terms'])) {
                    LegalPolicies::accept($user);
                }

                return $user;
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            if (User::whereRaw('LOWER(email) = ?', [$email])->exists()) {
                return null;
            }

            throw $exception;
        }
    }
}
