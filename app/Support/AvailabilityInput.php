<?php

namespace App\Support;

use App\Models\ProviderAvailabilitySetting;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class AvailabilityInput
{
    public static function normalize(array $input): array
    {
        if (isset($input['blocked_dates']) && is_string($input['blocked_dates'])) {
            $input['blocked_dates'] = array_values(array_filter(array_map('trim', preg_split('/\R/', $input['blocked_dates'])), fn ($date) => $date !== ''));
        }

        return $input;
    }

    public static function rules(): array
    {
        $time = ['nullable', 'string', 'regex:/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D'];

        return ['revision' => ['required', 'integer', 'min:0', 'max:2147483647'],
            'timezone' => ['required', 'string', Rule::in(ProviderAvailabilitySetting::timezones())],
            'intervals' => ['sometimes', 'array', 'max:42'], 'intervals.*' => ['array:day_of_week,start,end'],
            'intervals.*.day_of_week' => ['required', 'integer', 'between:1,7'],
            'intervals.*.start' => $time, 'intervals.*.end' => $time,
            'blocked_dates' => ['sometimes', 'array', 'max:100'], 'blocked_dates.*' => ['required', 'string', 'max:10', 'date_format:Y-m-d',
                'after_or_equal:2000-01-01', 'before_or_equal:2100-12-31', 'distinct:strict']]
            + array_fill_keys(['id', 'user_id', 'provider_profile_id', 'owner_id', 'settings_id', 'roles', 'permissions', 'is_admin',
                'created_at', 'updated_at', 'start_minute', 'end_minute'], ['prohibited']);
    }

    public static function messages(): array
    {
        return ['timezone.*' => 'Elige una zona horaria IANA de la lista.', 'revision.*' => 'Abre de nuevo la configuración antes de guardar.',
            'intervals.*' => 'Revisa los intervalos: hasta 42 filas con día, inicio y fin.',
            'intervals.*.day_of_week.*' => 'El día debe estar entre lunes (1) y domingo (7).',
            'intervals.*.start.*' => 'Usa una hora de inicio HH:MM entre 00:00 y 23:59.',
            'intervals.*.end.*' => 'Usa una hora de fin HH:MM entre 00:00 y 23:59.',
            'blocked_dates.*' => 'Introduce hasta 100 fechas válidas y distintas, entre 2000-01-01 y 2100-12-31.',
            'prohibited' => 'La propiedad y los permisos se comprueban en el servidor.'];
    }

    public static function checkIntervals($validator): void
    {
        if ($validator->errors()->isNotEmpty()) return;
        $days = [];
        foreach ($validator->getData()['intervals'] ?? [] as $index => $row) {
            $start = $row['start'] ?? null; $end = $row['end'] ?? null;
            if (($start === null || $start === '') && ($end === null || $end === '')) continue;
            if (! $start || ! $end || self::minute($start) >= self::minute($end)) {
                $validator->errors()->add('intervals.'.$index.'.end', 'Completa inicio y fin, con inicio anterior al fin y sin cruzar medianoche.');
                continue;
            }
            $days[(int) $row['day_of_week']][] = ['start' => self::minute($start), 'end' => self::minute($end), 'index' => $index];
        }
        foreach ($days as $rows) {
            usort($rows, fn ($a, $b) => $a['start'] <=> $b['start']); $previous = null;
            foreach ($rows as $row) {
                if ($previous && $row['start'] < $previous['end']) $validator->errors()->add('intervals.'.$row['index'].'.start', 'Los intervalos de un mismo día no pueden superponerse.');
                $previous = $row;
            }
        }
    }

    public static function minute(string $time): int
    {
        return (int) substr($time, 0, 2) * 60 + (int) substr($time, 3, 2);
    }

    public static function validated(array $input): array
    {
        $validator = Validator::make(self::normalize($input), self::rules(), self::messages());
        $validator->after(fn ($validator) => self::checkIntervals($validator));

        return $validator->validate();
    }
}
