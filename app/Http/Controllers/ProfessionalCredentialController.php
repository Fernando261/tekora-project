<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Models\ProfessionalCredential;
use App\Models\User;
use App\Services\ProfessionalCredentials;
use App\Support\TrustSchema;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class ProfessionalCredentialController extends Controller
{
    public function edit(Request $request)
    {
        abort_unless(TrustSchema::available(), 503);

        return view('trust.edit', ['credential' => ProfessionalCredential::find($request->user('web')->id)]);
    }

    public function update(Request $request, ProfessionalCredentials $credentials)
    {
        abort_unless(TrustSchema::available(), 503);
        $input = $request->validate(ProfessionalCredentials::licenseRules(true, true));
        $credentials->update($request->user('web'), $input);

        return redirect()->route('trust.edit', [], 303)->with('success', 'Datos profesionales guardados.');
    }

    public function index()
    {
        abort_unless(TrustSchema::available(), 503);
        $credentials = ProfessionalCredential::with('owner:id,name')->orderByDesc('user_id')->paginate(20);

        return view('trust.review', compact('credentials'));
    }

    public function decide(Request $request, User $owner, ProfessionalCredentials $credentials)
    {
        abort_unless(TrustSchema::available(), 503);
        $credentials->review($request->user('web'), $owner, $request->validate([
            'revision' => ['required', 'integer', 'min:1'],
            'decision' => ['required', Rule::in(['verified', 'rejected', 'revoked'])],
            'reason' => ['required', 'string', 'max:500'],
        ]));

        return redirect()->route('trust.review', [], 303)->with('success', 'Decisión registrada.');
    }

    public function download(Request $request, User $owner)
    {
        abort_unless(TrustSchema::available(), 503);
        $actor = $request->user('web');
        abort_unless(($actor->id === $owner->id && $actor->hasPermission(Permission::ManageProviderProfile))
            || $actor->hasPermission(Permission::ReviewProviderProfiles), 404);
        $credential = ProfessionalCredential::findOrFail($owner->id);
        $storage = Storage::disk('professional');
        abort_unless(Str::isUuid($credential->evidence_path) && $storage->exists($credential->evidence_path), 404);
        $extension = match ($credential->evidence_mime) {
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            default => abort(404),
        };

        return $storage->download($credential->evidence_path, 'certificado.'.$extension, [
            'Content-Type' => 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
