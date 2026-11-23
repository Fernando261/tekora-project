<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Local\LocalFilesystemAdapter;
use RuntimeException;
use Throwable;

final class ProfilePhotos
{
    public function replace(User $user, UploadedFile $file): bool
    {
        $connection = $user->getConnection();
        $disk = null;
        $path = null;
        $previous = null;
        $ownsNewFile = false;
        $transactionStarted = false;
        $phase = 'write';

        try {
            // Own the outer transaction: a savepoint is not a confirmed SQL commit.
            if ($connection->transactionLevel() !== 0 || $connection->getPdo()->inTransaction()) {
                throw new RuntimeException('An outer transaction is already active.');
            }
            if (! $file->isValid()) {
                throw new RuntimeException('Invalid upload.');
            }

            $disk = Storage::disk('public');
            $extension = strtolower($file->getClientOriginalExtension());
            if (! in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
                $extension = $file->extension();
            }
            $path = 'avatars/avatar_'.$user->getKey().'_'.bin2hex(random_bytes(16)).'.'.$extension;
            if (! $this->insidePublicRoot($disk, $path) || $disk->exists($path)) {
                throw new RuntimeException('The new location is unavailable.');
            }

            // Validation bounds this string to 2048 KB; no caller-owned stream to leak.
            $contents = $file->getContent();
            $ownsNewFile = true;
            if ($disk->put($path, $contents) !== true
                || ! $disk->exists($path)
                || $disk->size($path) !== strlen($contents)
                || ! hash_equals(hash('sha256', $contents), hash('sha256', $disk->get($path)))) {
                throw new RuntimeException('The stored bytes could not be verified.');
            }

            $phase = 'persistence';
            $transactionStarted = true;
            $connection->beginTransaction();
            // Never use an authenticated instance's stale path or other dirty attributes.
            $current = $user->newQuery()->useWritePdo()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $previous = $current->profile_photo_path;
            $current->profile_photo_path = $path;
            if (! $current->save()
                || $current->newQuery()->useWritePdo()->whereKey($current->getKey())->value('profile_photo_path') !== $path) {
                throw new RuntimeException('The reference was not saved.');
            }
            $connection->commit();
            if ($connection->transactionLevel() !== 0 || $connection->getPdo()->inTransaction()) {
                throw new RuntimeException('The transaction did not complete.');
            }
            if ($user->newQuery()->useWritePdo()->whereKey($user->getKey())->value('profile_photo_path') !== $path) {
                throw new RuntimeException('The committed reference could not be confirmed.');
            }
            $transactionStarted = false;
        } catch (Throwable) {
            $rolledBack = true;
            if ($transactionStarted) {
                try {
                    $connection->rollBack();
                    // Also handle a driver returning false with an open PDO transaction.
                    if ($connection->getPdo()->inTransaction()) {
                        $connection->getPdo()->rollBack();
                    }
                    $rolledBack = $connection->transactionLevel() === 0;
                } catch (Throwable) {
                    $rolledBack = false;
                    $this->warn('rollback-unconfirmed');
                }
            }
            if ($ownsNewFile && $rolledBack) {
                $this->removeUnreferencedPhoto($disk, $user, $path, 'new-file-compensation');
            }
            $this->warn($phase);

            return false;
        }

        if (is_string($previous) && $previous !== '' && $previous !== $path) {
            $this->removeUnreferencedPhoto($disk, $user, $previous, 'previous-file-cleanup');
        }

        try {
            // Keep this request's cached user consistent without saving stale attributes.
            $user->profile_photo_path = $user->newQuery()->useWritePdo()->whereKey($user->getKey())->value('profile_photo_path');
            $user->syncOriginalAttribute('profile_photo_path');
        } catch (Throwable) {
            $this->warn('user-reference-refresh');
        }

        return true;
    }

    public function readablePath(User $user): ?string
    {
        $path = $user->profile_photo_path;
        if (! is_string($path) || ! $this->isManagedPath($user, $path)) {
            return null;
        }

        $disk = Storage::disk('public');
        if (! $this->insidePublicRoot($disk, $path) || ! $disk->exists($path) || $disk->size($path) > 2048 * 1024) {
            return null;
        }

        return $path;
    }

    private function isManagedPath(User $user, string $path): bool
    {
        return preg_match('#\A(?:avatars|historical)/[a-zA-Z0-9][a-zA-Z0-9_-]*\.(?i:jpg|jpeg|png|webp)\z#', $path)
            && (! preg_match('#\A(?:avatars|historical)/avatar_([0-9]+)_#i', $path, $owner) || $owner[1] === (string) $user->getKey());
    }

    private function removeUnreferencedPhoto(FilesystemAdapter $disk, User $user, string $path, string $phase): void
    {
        try {
            // Flat avatar/legacy-photo names only; never arbitrary module paths or URLs.
            if (! $this->isManagedPath($user, $path) || ! $this->insidePublicRoot($disk, $path)) {
                $this->warn($phase.'-unmanaged');

                return;
            }
            $connection = $user->getConnection();
            // An ambiguous commit/read failure must retain a possibly referenced file.
            if ($connection->transactionLevel() !== 0 || $connection->getPdo()->inTransaction()
                || $this->photoMayBeReferenced($user, $path)) {
                return;
            }
            if ($disk->exists($path) && $disk->delete($path) !== true) {
                $this->warn($phase);
            }
        } catch (Throwable) {
            $this->warn($phase);
        }
    }

    private function photoMayBeReferenced(User $user, string $path): bool
    {
        return app(ProfilePhotoReferences::class)->mayReference($user, $path);
    }

    private function insidePublicRoot(FilesystemAdapter $disk, string $path): bool
    {
        if (! $disk->getAdapter() instanceof LocalFilesystemAdapter) {
            return true; // Non-local disks use the validated, relative object key.
        }
        $root = realpath($disk->path(''));
        $location = $disk->path($path);
        while (! file_exists($location) && ! is_link($location) && dirname($location) !== $location) {
            $location = dirname($location);
        }
        $location = realpath($location);

        return $root !== false && $location !== false
            && ($location === $root || str_starts_with($location, $root.DIRECTORY_SEPARATOR));
    }

    private function warn(string $phase): void
    {
        try {
            Log::warning('Profile photo operation needs attention.', ['phase' => $phase]);
        } catch (Throwable) {
            // Logger failure cannot change a committed reference or trigger unsafe cleanup.
        }
    }
}
