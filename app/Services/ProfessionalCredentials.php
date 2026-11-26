<?php

namespace App\Services;

use App\Enums\Permission;
use App\Models\ProfessionalCredential;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

final class ProfessionalCredentials
{
    public const LABELS = ['unverified' => 'Sin verificar', 'pending' => 'Pendiente', 'verified' => 'Verificado', 'rejected' => 'Rechazado'];

    public static function licenseRules(bool $required, bool $withEvidence = false): array
    {
        return [
            'license_disposition' => [Rule::requiredIf($required), 'nullable', Rule::in(['available', 'none'])],
            'license_number' => ['exclude_unless:license_disposition,available', 'required', 'string', 'regex:/\A[0-9]{5,16}\z/'],
            'public_license' => ['exclude_unless:license_disposition,available', 'sometimes', 'accepted'],
        ] + ($withEvidence ? [
            'certificate' => ['nullable', 'prohibits:remove_certificate', 'file', 'max:5120', 'mimetypes:application/pdf,image/jpeg,image/png'],
            'remove_certificate' => ['sometimes', 'accepted'],
        ] : []);
    }

    public function enroll(User $owner, array $input): void
    {
        if (! isset($input['license_disposition'])) {
            return;
        }

        $this->update($owner, $input, true);
    }

    public function update(User $owner, array $input, bool $initial = false): void
    {
        $file = $input['certificate'] ?? null;
        $mime = $file ? ((new \finfo(FILEINFO_MIME_TYPE))->file($file->getPathname()) ?: null) : null;
        $number = $input['license_disposition'] === 'available' ? $input['license_number'] : null;
        $public = $number !== null && isset($input['public_license']);
        $remove = isset($input['remove_certificate']);
        $path = $file ? $this->upload($file, $mime) : null;

        try {
            $previous = DB::transaction(function () use ($owner, $number, $public, $remove, $path, $mime, $initial) {
                $this->lockOwner($owner);
                $credential = ProfessionalCredential::find($owner->id);
                if ($initial && $credential) {
                    return null;
                }
                $oldPath = $credential?->evidence_path;
                $newPath = $remove ? null : ($path ?? $oldPath);
                $credential ??= new ProfessionalCredential;
                $changed = ! $credential->exists || $number !== $credential->license_number || $newPath !== $oldPath;
                $credential->user_id = $owner->id;
                $credential->license_number = $number;
                $credential->public_license = $public;
                $credential->evidence_path = $newPath;
                $credential->evidence_mime = $newPath === null ? null : ($mime ?? $credential->evidence_mime);
                if ($changed) {
                    $credential->status = $number || $newPath ? 'pending' : 'unverified';
                    $credential->revision = ($credential->revision ?? 0) + 1;
                }
                $credential->save();

                return $oldPath !== $newPath ? $oldPath : null;
            }, 3);
        } catch (Throwable $error) {
            // Keep staged evidence if storage cannot establish whether a commit took effect.
            if ($path !== null) {
                rescue(function () use ($path) {
                    if (! ProfessionalCredential::where('evidence_path', $path)->exists()) {
                        Storage::disk('professional')->delete($path);
                    }
                });
            }
            throw $error;
        }
        if ($previous !== null) {
            Storage::disk('professional')->delete($previous);
        }
    }

    public function review(User $reviewer, User $owner, array $input): void
    {
        DB::transaction(function () use ($reviewer, $owner, $input) {
            $this->lockOwner($owner, $input['decision'] !== 'revoked');
            abort_unless($reviewer->hasPermission(Permission::ReviewProviderProfiles) && $reviewer->id !== $owner->id, 403);
            $credential = ProfessionalCredential::findOrFail($owner->id);
            $decision = $input['decision'];
            abort_unless($credential->revision === (int) $input['revision']
                && $credential->status === ($decision === 'revoked' ? 'verified' : 'pending'), 409, 'La documentación o su estado cambiaron.');
            $credential->status = $decision === 'revoked' ? 'unverified' : $decision;
            $credential->save();
            DB::table('professional_reviews')->insert([
                'provider_user_id' => $owner->id, 'reviewer_user_id' => $reviewer->id,
                'revision' => $credential->revision, 'decision' => $decision,
                'reason' => $input['reason'], 'reviewed_at' => now('UTC'),
            ]);
        }, 3);
    }

    private function lockOwner(User $owner, bool $requireProvider = true): void
    {
        $current = User::whereKey($owner->id)->lockForUpdate()->firstOrFail();
        abort_unless(! $requireProvider || $current->hasPermission(Permission::ManageProviderProfile), 403);
    }

    private function upload(UploadedFile $file, ?string $mime): string
    {
        $image = in_array($mime, ['image/jpeg', 'image/png'], true);
        if ((! $image && $mime !== 'application/pdf') || ($image && ! getimagesize($file->getPathname()))) {
            throw ValidationException::withMessages(['certificate' => 'Selecciona un PDF, JPG o PNG válido.']);
        }

        $stored = $file->storeAs('', (string) Str::uuid(), ['disk' => 'professional', 'visibility' => 'private']);
        abort_unless(is_string($stored), 503, 'El archivo privado no pudo guardarse.');

        return $stored;
    }
}
