<?php

namespace App\Services;

use App\Enums\Permission;
use App\Models\ProfessionalCredential;
use App\Models\User;
use finfo;
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

    public static function licenseRules(bool $required): array
    {
        return [
            'license_disposition' => [Rule::requiredIf($required), 'nullable', Rule::in(['available', 'none'])],
            'license_number' => ['exclude_unless:license_disposition,available', 'required', 'string', 'regex:/\A[0-9]{5,16}\z/'],
            'public_license' => ['exclude_unless:license_disposition,available', 'sometimes', 'accepted'],
        ];
    }

    public function enroll(User $owner, array $input): void
    {
        if (isset($input['license_disposition'])) {
            $this->declareLicense($owner, $input, initial: true);
        }
    }

    public function declareLicense(User $owner, array $input, bool $initial = false): void
    {
        DB::transaction(function () use ($owner, $input, $initial) {
            $this->lockOwner($owner);
            $credential = ProfessionalCredential::lockForUpdate()->find($owner->id);
            if ($initial && $credential) {
                return;
            }
            if (! $credential) {
                $credential = new ProfessionalCredential;
                $credential->user_id = $owner->id;
                $credential->status = 'unverified';
                $credential->revision = 1;
            }
            $credential->license_number = $input['license_disposition'] === 'available' ? $input['license_number'] : null;
            $credential->public_license = $credential->license_number !== null && isset($input['public_license']);
            $credential->save();
        }, 3);
    }

    public function updateCertificate(User $owner, ?UploadedFile $file): void
    {
        $mime = $file ? self::certificateMime($file->getPathname()) : null;
        if ($file && $mime === null) {
            throw ValidationException::withMessages(['certificate' => 'Selecciona un PDF, JPG o PNG válido.']);
        }
        $path = $file ? $this->upload($file) : null;

        try {
            $previous = DB::transaction(function () use ($owner, $path, $mime) {
                $this->lockOwner($owner);
                $credential = ProfessionalCredential::lockForUpdate()->findOrFail($owner->id);
                $oldPath = $credential->evidence_path;
                if ($path === $oldPath) {
                    return null;
                }
                $credential->evidence_path = $path;
                $credential->evidence_mime = $mime;
                $credential->status = $path === null ? 'unverified' : 'pending';
                $credential->revision++;
                $credential->save();

                return $oldPath;
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
            $credential = ProfessionalCredential::lockForUpdate()->findOrFail($owner->id);
            $decision = $input['decision'];
            abort_unless($credential->revision === (int) $input['revision']
                && $credential->status === ($decision === 'revoked' ? 'verified' : 'pending'), 409, 'La documentación o su estado cambiaron.');
            abort_if($decision === 'verified' && ! self::hasValidCertificate($credential), 409, 'Se requiere un certificado privado vigente y válido.');
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

    public static function hasValidCertificate(ProfessionalCredential $credential): bool
    {
        if (! Str::isUuid($credential->evidence_path) || $credential->evidence_mime === null) {
            return false;
        }

        return self::certificateMime(Storage::disk('professional')->path($credential->evidence_path)) === $credential->evidence_mime;
    }

    private static function certificateMime(string $path): ?string
    {
        if (! is_file($path) || ! is_readable($path) || filesize($path) > 5 * 1024 * 1024) {
            return null;
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
        if ($mime === 'application/pdf') {
            return $mime;
        }

        return in_array($mime, ['image/jpeg', 'image/png'], true) && getimagesize($path) ? $mime : null;
    }

    private function upload(UploadedFile $file): string
    {
        $stored = $file->storeAs('', (string) Str::uuid(), ['disk' => 'professional', 'visibility' => 'private']);
        abort_unless(is_string($stored), 503, 'El archivo privado no pudo guardarse.');

        return $stored;
    }
}
