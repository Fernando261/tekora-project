<?php

namespace App\Enums;

enum InquiryNotificationEvent: string
{
    case Created = 'created';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::Created => 'Nueva solicitud',
            self::Accepted => 'Solicitud aceptada',
            self::Rejected => 'Solicitud rechazada',
            self::Cancelled => 'Solicitud cancelada',
            self::Completed => 'Solicitud finalizada',
        };
    }

    public function title(): string
    {
        return match ($this) {
            self::Created => 'Recibiste una solicitud para uno de tus servicios.',
            self::Accepted => 'El prestador aceptó tu solicitud.',
            self::Rejected => 'El prestador rechazó tu solicitud.',
            self::Cancelled => 'El cliente canceló su solicitud.',
            self::Completed => 'El prestador marcó tu solicitud como finalizada.',
        };
    }

    public function forProvider(): bool
    {
        return in_array($this, [self::Created, self::Cancelled], true);
    }
}
