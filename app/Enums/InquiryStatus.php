<?php

namespace App\Enums;

enum InquiryStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendiente',
            self::Accepted => 'Aceptada',
            self::Rejected => 'Rechazada',
            self::Cancelled => 'Cancelada',
            self::Completed => 'Finalizada',
        };
    }
}
