<?php

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Validation\ValidationException;

class ReservationTime
{
    /** @return array{starts_at_utc: string, ends_at_utc: string, local_start_minute: int, weekday: int} */
    public function resolve(string $date, string $time, string $timezone): array
    {
        $civil = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date.' '.$time, new DateTimeZone('UTC'));
        if (! $civil || $civil->format('Y-m-d H:i') !== $date.' '.$time) $this->invalid();
        $civilEnd = $civil->modify('+60 minutes');
        if ($civilEnd->format('Y-m-d') !== $date) $this->invalid('La reserva de 60 minutos debe terminar el mismo día, antes de medianoche.');

        // Enumerar offsets posibles y exigir una única conversión exacta, sin la normalización silenciosa de PHP.
        $zone = new DateTimeZone($timezone);
        $start = $this->uniqueInstant($civil, $zone);
        $end = $this->uniqueInstant($civilEnd, $zone);
        if ($end - $start !== 3600 || $zone->getOffset(new DateTimeImmutable('@'.$start)) !== $zone->getOffset(new DateTimeImmutable('@'.$end))) {
            $this->invalid('El intervalo cruza un cambio de horario. Elige otro horario de 60 minutos.');
        }

        return ['starts_at_utc' => gmdate('Y-m-d H:i:s', $start), 'ends_at_utc' => gmdate('Y-m-d H:i:s', $end),
            'local_start_minute' => (int) $civil->format('H') * 60 + (int) $civil->format('i'), 'weekday' => (int) $civil->format('N')];
    }

    private function uniqueInstant(DateTimeImmutable $civil, DateTimeZone $zone): int
    {
        $wall = $civil->getTimestamp();
        $transitions = $zone->getTransitions($wall - 172800, $wall + 172800);
        $offsets = array_unique(array_column($transitions ?: [], 'offset'));
        $matches = [];
        foreach ($offsets as $offset) {
            $candidate = $wall - $offset;
            if ((new DateTimeImmutable('@'.$candidate))->setTimezone($zone)->format('Y-m-d H:i') === $civil->format('Y-m-d H:i')) $matches[] = $candidate;
        }
        if (count($matches) !== 1) $this->invalid('La hora local no existe o es ambigua por un cambio de horario. Elige otra hora.');

        return $matches[0];
    }

    private function invalid(string $message = 'La fecha o la hora local no es válida.'): never
    {
        throw ValidationException::withMessages(['time' => $message]);
    }
}
