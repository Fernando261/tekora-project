<?php

namespace App\Enums;

enum ServiceModality: string
{
    case Remote = 'remote';
    case Onsite = 'onsite';
    case Both = 'both';

    public function label(): string
    {
        return match ($this) {
            self::Remote => 'Remoto',
            self::Onsite => 'Presencial',
            self::Both => 'Remoto y presencial',
        };
    }
}
