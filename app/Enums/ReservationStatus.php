<?php

namespace App\Enums;

enum ReservationStatus: string
{
    case Requested = 'requested';
    case Confirmed = 'confirmed';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
    case Completed = 'completed';

    public function active(): bool
    {
        return in_array($this, [self::Requested, self::Confirmed], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Requested => 'Horario propuesto',
            self::Confirmed => 'Reserva confirmada',
            self::Rejected => 'Propuesta rechazada',
            self::Cancelled => 'Reserva cancelada',
            self::Completed => 'Servicio realizado',
        };
    }
}
