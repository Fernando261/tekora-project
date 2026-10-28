<?php

namespace App\Enums;

enum ServiceStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Paused = 'paused';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Borrador',
            self::Published => 'Publicado',
            self::Paused => 'Pausado',
        };
    }
}
