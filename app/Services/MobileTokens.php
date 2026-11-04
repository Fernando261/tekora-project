<?php

namespace App\Services;

use App\Enums\Permission;
use App\Enums\RoleName;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\NewAccessToken;

final class MobileTokens
{
    public function login(array $input): ?NewAccessToken
    {
        $user = User::where('email', $input['email'])->first();
        $valid = Hash::check($input['password'], $user?->password ?? config('mobile_api.dummy_password_hash'));
        if (! $valid || ! $user) {
            return null;
        }

        return DB::transaction(function () use ($user, $input) {
            // Serializar emisiones por usuario. SQLite protege la promoción lectura/escritura;
            // un conflicto agotado se devuelve como 503, sin emitir un token fuera del límite.
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            if (! Hash::check($input['password'], $user->password)
                || ! $user->hasRole(RoleName::Client)
                || ! $user->hasPermission(Permission::ViewClientAppointments)
                || ! $user->hasPermission(Permission::ManageOwnInquiries)) {
                return null;
            }

            $name = 'mobile:'.$input['device_name'];
            $tokens = $user->tokens()->where('name', 'like', 'mobile:%');
            (clone $tokens)->where(fn ($query) => $query->where('name', $name)->orWhere('expires_at', '<=', now()))->delete();
            $keep = max(0, (int) config('mobile_api.max_tokens') - 1);
            $ids = (clone $tokens)->orderByDesc('id')->pluck('id')->slice($keep);
            if ($ids->isNotEmpty()) {
                (clone $tokens)->whereIn('id', $ids)->delete();
            }

            return $user->createToken($name, ['client:read'], now()->addDays(config('mobile_api.token_days')));
        }, 3);
    }
}
