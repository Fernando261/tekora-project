<?php

namespace App\Services;

use App\Exceptions\AvailabilityFailure;
use App\Models\ProviderAvailabilitySetting;
use App\Models\ProviderProfile;
use App\Models\User;
use App\Support\AvailabilityInput;
use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ProviderAvailability
{
    use DetectsConcurrencyErrors;

    public function save(User $user, array $input): ProviderAvailabilitySetting
    {
        Gate::forUser($user)->authorize('create', ProviderProfile::class);
        $profile = $user->providerProfile()->firstOrFail();
        Gate::forUser($user)->authorize('update', $profile);
        $data = AvailabilityInput::validated($input);
        try {
            return DB::transaction(function () use ($user, $profile, $data) {
                Gate::forUser($user)->authorize('update', $profile->fresh());
                $setting = $profile->availabilitySetting()->first();
                if (! $setting) {
                    if ((int) $data['revision'] !== 0) throw new AvailabilityFailure;
                    $setting = new ProviderAvailabilitySetting;
                    $setting->provider_profile_id = $profile->id; $setting->timezone = $data['timezone']; $setting->revision = 1;
                    $setting->save();
                } else {
                    $changed = ProviderAvailabilitySetting::whereKey($setting->id)->where('revision', (int) $data['revision'])->update([
                        'timezone' => $data['timezone'], 'revision' => $setting->revision + 1, 'updated_at' => now()]);
                    if ($changed !== 1) throw new AvailabilityFailure;
                }
                // Solo se reemplazan intervalos/fechas de este perfil en las tablas nuevas, dentro del mismo bloqueo.
                $profile->weeklyIntervals()->delete();
                foreach ($data['intervals'] ?? [] as $row) {
                    if (empty($row['start']) && empty($row['end'])) continue;
                    $profile->weeklyIntervals()->getQuery()->insert(['provider_profile_id' => $profile->id, 'day_of_week' => (int) $row['day_of_week'],
                        'start_minute' => AvailabilityInput::minute($row['start']), 'end_minute' => AvailabilityInput::minute($row['end'])]);
                }
                $profile->blockedDates()->delete();
                foreach ($data['blocked_dates'] ?? [] as $date) $profile->blockedDates()->getQuery()->insert(['provider_profile_id' => $profile->id, 'blocked_date' => $date]);

                return $setting->fresh();
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            throw new AvailabilityFailure;
        } catch (\PDOException $exception) {
            throw new AvailabilityFailure($this->causedByConcurrencyError($exception) ? 409 : 503);
        }
    }

    public function publicFor(ProviderProfile $profile): ?array
    {
        // Una sola instantánea: no mezclar una zona antigua con intervalos de una edición posterior.
        try {
            return $profile->getConnection()->transaction(function () use ($profile) {
                if (! $profile->services()->publiclyVisible()->exists()) return null;
                $setting = $profile->availabilitySetting()->first();
                if (! $setting) return null;
                $today = now($setting->timezone)->format('Y-m-d');

                return ['timezone' => $setting->timezone,
                    'days' => $profile->weeklyIntervals()->orderBy('day_of_week')->orderBy('start_minute')->get()->groupBy('day_of_week'),
                    'blocked_dates' => $profile->blockedDates()->where('blocked_date', '>=', $today)->orderBy('blocked_date')->pluck('blocked_date')];
            }, 3);
        } catch (\PDOException $exception) {
            // No presentar un bloqueo/error como disponibilidad libre ni exponer el SQL de la lectura.
            return ['unavailable' => true];
        }
    }
}
