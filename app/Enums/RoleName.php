<?php

namespace App\Enums;

enum RoleName: string
{
    case Client = 'client';
    case Provider = 'provider';
    case Admin = 'admin';

    public static function businessValues(): array
    {
        return [self::Client->value, self::Provider->value];
    }
}
