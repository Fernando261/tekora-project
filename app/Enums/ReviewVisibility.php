<?php

namespace App\Enums;

enum ReviewVisibility: string
{
    case Published = 'published';
    case Withdrawn = 'withdrawn';

    public function label(): string
    {
        return $this === self::Published ? 'Publicada' : 'Retirada';
    }
}
