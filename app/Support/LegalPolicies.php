<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;

final class LegalPolicies
{
    public const VERSION = '2026-11-26-v1';

    public const TITLES = [
        'privacidad' => 'Aviso de privacidad',
        'terminos' => 'Términos y condiciones',
        'cookies' => 'Política de cookies',
        'cancelaciones' => 'Política de cancelaciones',
        'reembolsos' => 'Política de reembolsos',
        'prestadores' => 'Condiciones para prestadores',
    ];

    public static function accept(User $user): void
    {
        // Serialize requests for the same account and retain the first timestamp.
        DB::transaction(function () use ($user) {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $key = ['user_id' => $user->id, 'version' => self::VERSION];
            if (! DB::table('terms_acceptances')->where($key)->exists()) {
                DB::table('terms_acceptances')->insert($key + ['accepted_at' => now('UTC')]);
            }
        }, 3);
    }
}
