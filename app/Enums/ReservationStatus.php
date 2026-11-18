<?php

namespace App\Enums;

enum ReservationStatus: string
{
    case Requested = 'requested';
    // Effective phases of a quoted proposal; historical SQL statuses remain intact.
    case Held = 'held';
    case HoldExpired = 'hold_expired';
    case HoldReleased = 'hold_released';
    case Confirmed = 'confirmed';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
    case Completed = 'completed';

    public function active(): bool
    {
        return in_array($this, [self::Requested, self::Held, self::Confirmed], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Requested => 'Horario propuesto',
            self::Held => 'Horario reservado temporalmente',
            self::HoldExpired => 'Retención vencida',
            self::HoldReleased => 'Retención liberada',
            self::Confirmed => 'Reserva confirmada',
            self::Rejected => 'Propuesta rechazada',
            self::Cancelled => 'Reserva cancelada',
            self::Completed => 'Servicio realizado',
        };
    }
}
