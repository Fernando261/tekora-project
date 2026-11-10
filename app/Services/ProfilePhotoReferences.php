<?php

namespace App\Services;

use App\Models\User;

class ProfilePhotoReferences
{
    public function __construct(private LegacyPhotoReferences $legacy) {}

    public function mayReference(User $user, string $path): bool
    {
        // Keep URL/query/fragment and case-insensitive matches conservative.
        $pattern = '%'.str_replace('_', '!_', strtolower($path)).'%';
        if ($user->newQuery()->useWritePdo()
            ->whereRaw("LOWER(profile_photo_path) LIKE ? ESCAPE '!'", [$pattern])->exists()) {
            return true;
        }

        return $this->legacy->mayReference($user->getConnection(), $pattern);
    }
}
