<?php

namespace App\Services;

use App\Enums\RoleName;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Support\Timebox;
use RuntimeException;
use SensitiveParameter;

final class AccountPasswords
{
    public function mobileAccount(string $email, bool $lock = false): ?User
    {
        $query = User::whereRaw('LOWER(email) = ?', [Str::lower($email)])->limit(2);
        if ($lock) {
            $query->lockForUpdate();
        }
        $matches = $query->get();

        return $matches->count() === 1 && $matches[0]->hasRole(RoleName::Client) ? $matches[0] : null;
    }

    public function reset(#[SensitiveParameter] array $input, bool $clientOnly = false): string
    {
        $reset = fn () => DB::transaction(function () use ($input, $clientOnly) {
            // Both entry points lock the account before the broker checks and consumes its token.
            $account = $clientOnly ? $this->mobileAccount($input['email'], lock: true)
                : User::where('email', $input['email'])->lockForUpdate()->first();
            if (! $account) {
                return Password::INVALID_USER;
            }

            return Password::broker()->reset([
                'email' => $account->email,
                'token' => $input['token'],
                'password' => $input['password'],
            ], function (User $user, string $password) {
                $saved = $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();
                if (! $saved) {
                    throw new RuntimeException('No se pudo guardar la contraseña.');
                }
                $user->tokens()->delete();
            });
        }, 3);

        // Match the broker's timing floor even if the mobile account lookup fails.
        return $clientOnly ? app(Timebox::class)->call($reset, (int) config('auth.timebox_duration', 200000) + 50000) : $reset();
    }
}
