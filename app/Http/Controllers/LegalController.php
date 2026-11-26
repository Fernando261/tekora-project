<?php

namespace App\Http\Controllers;

use App\Support\LegalPolicies;
use App\Support\TrustSchema;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Validation\Rule;

final class LegalController extends Controller
{
    public function show(string $policy)
    {
        abort_unless(isset(LegalPolicies::TITLES[$policy]), 404);

        return view('legal.show', ['policy' => $policy, 'title' => LegalPolicies::TITLES[$policy]]);
    }

    public function accept(Request $request)
    {
        abort_unless(TrustSchema::available(), 503);
        $request->validate([
            'accept_terms' => ['required', 'accepted'],
            'terms_version' => ['required', Rule::in([LegalPolicies::VERSION])],
        ]);
        LegalPolicies::accept($request->user('web'));

        return redirect()->route('account.index', [], 303)->with('success', 'Aceptación de términos registrada.');
    }

    public function cookies(Request $request)
    {
        $data = $request->validate(['optional' => ['required', Rule::in(['accepted', 'rejected'])]]);
        Cookie::queue(cookie('tekora_preferences_v1', $data['optional'], 60 * 24 * 180, '/', null, $request->isSecure(), true, false, 'lax'));

        return redirect()->route('legal.show', 'cookies', 303)->with('success', 'Preferencia de cookies guardada.');
    }
}
