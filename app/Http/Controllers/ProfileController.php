<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Local\LocalFilesystemAdapter;
use RuntimeException;
use Throwable;

class ProfileController extends Controller
{
    public function updatePhoto(Request $request)
    {
        $request->session()->forget('success');
        $request->validate([
            'photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

        $file = $request->file('photo');
        $user = $request->user('web');
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
            if (! $file || ! $file->isValid()) {
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
            $request->session()->forget('success');

            return back()->withErrors(['photo' => 'No se pudo actualizar la foto. Inténtalo de nuevo.']);
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

        return back()->with('success', 'Foto de perfil actualizada.');
    }

    private function removeUnreferencedPhoto(FilesystemAdapter $disk, User $user, string $path, string $phase): void
    {
        try {
            // Flat avatar/legacy-photo names only; never arbitrary module paths or URLs.
            if (! preg_match('#\A(?:avatars|historical)/[a-zA-Z0-9][a-zA-Z0-9_-]*\.(?i:jpg|jpeg|png|webp)\z#', $path)
                || (preg_match('#\A(?:avatars|historical)/avatar_([0-9]+)_#i', $path, $owner) && $owner[1] !== (string) $user->getKey())
                || ! $this->insidePublicRoot($disk, $path)) {
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
        // Retain possible references even within old URLs, cache queries or fragments.
        $referencePattern = '%'.str_replace('_', '!_', strtolower($path)).'%';
        $connection = $user->getConnection();
        if ($user->newQuery()->useWritePdo()->whereRaw("LOWER(profile_photo_path) LIKE ? ESCAPE '!'", [$referencePattern])->exists()) {
            return true;
        }
        foreach (['posts' => 'media_path', 'comments' => 'media_path', 'emotions' => 'image_path'] as $table => $column) {
            if ($connection->table($table)->useWritePdo()->whereRaw("LOWER({$column}) LIKE ? ESCAPE '!'", [$referencePattern])->exists()) {
                return true;
            }
        }

        return false;
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
