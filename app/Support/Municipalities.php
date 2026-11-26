<?php

namespace App\Support;

final class Municipalities
{
    public const NAMES = [
        'guadalajara' => 'Guadalajara',
        'zapopan' => 'Zapopan',
        'san-pedro-tlaquepaque' => 'San Pedro Tlaquepaque',
        'tonala' => 'Tonalá',
        'tlajomulco-de-zuniga' => 'Tlajomulco de Zúñiga',
        'el-salto' => 'El Salto',
        'juanacatlan' => 'Juanacatlán',
        'ixtlahuacan-de-los-membrillos' => 'Ixtlahuacán de los Membrillos',
        'zapotlanejo' => 'Zapotlanejo',
    ];

    public static function rows(): array
    {
        return array_map(fn (string $slug, string $name) => compact('slug', 'name'), array_keys(self::NAMES), self::NAMES);
    }
}
