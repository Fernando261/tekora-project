<?php

namespace App\Http\Controllers;

use App\Exceptions\AvailabilityFailure;
use App\Http\Requests\ProviderAvailabilityRequest;
use App\Models\ProviderAvailabilitySetting;
use App\Models\ProviderProfile;
use App\Models\ProviderWeeklyInterval;
use App\Services\ProviderAvailability;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ProviderAvailabilityController extends Controller
{
    public function edit(Request $request)
    {
        $user = $request->user('web');
        Gate::forUser($user)->authorize('create', ProviderProfile::class);
        $profile = $user->providerProfile()->first();
        if (! $profile) return redirect()->route('account.provider')->with('info', 'Completa primero tu perfil profesional para publicar horarios.');
        Gate::forUser($user)->authorize('update', $profile);
        try {
            [$setting, $intervals, $blockedDates] = $profile->getConnection()->transaction(fn () => [
                $profile->availabilitySetting()->first(),
                $profile->weeklyIntervals()->orderBy('day_of_week')->orderBy('start_minute')->get()->map(fn ($row) => [
                    'day_of_week' => $row->day_of_week, 'start' => ProviderWeeklyInterval::civilTime($row->start_minute), 'end' => ProviderWeeklyInterval::civilTime($row->end_minute)])->all(),
                $profile->blockedDates()->orderBy('blocked_date')->pluck('blocked_date')->all(),
            ], 3);
        } catch (\PDOException $exception) {
            return response()->view('availability.error', ['heading' => 'No se pudo consultar la disponibilidad',
                'message' => 'La configuración no está disponible temporalmente. Recarga los horarios e inténtalo de nuevo.'], 503);
        }
        $timezones = ProviderAvailabilitySetting::timezones();

        return view('availability.edit', compact('profile', 'setting', 'intervals', 'blockedDates', 'timezones'));
    }

    public function update(ProviderAvailabilityRequest $request, ProviderAvailability $availability)
    {
        try {
            $availability->save($request->user('web'), $request->validated());

            return redirect()->route('availability.edit', [], 303)->with('success', 'Disponibilidad informativa guardada.');
        } catch (AvailabilityFailure $exception) {
            if ($request->expectsJson()) return response()->json(['message' => $exception->getMessage()], $exception->status);

            return response()->view('availability.error', ['message' => $exception->getMessage()], $exception->status);
        }
    }
}
