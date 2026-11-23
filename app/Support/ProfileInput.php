<?php

namespace App\Support;

final class ProfileInput
{
    public static function name(): array
    {
        return ['required', 'string', 'max:255'];
    }

    public static function photo(): array
    {
        return ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'];
    }
}
