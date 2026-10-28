<?php

namespace Database\Seeders;

use App\Models\ServiceCategory;
use Illuminate\Database\Seeder;

class ServiceCategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['reparacion-celulares', 'Reparación de celulares', 'Diagnóstico y reparación de dispositivos móviles.'],
            ['computadoras', 'Computadoras y mantenimiento', 'Mantenimiento y reparación de equipos de cómputo.'],
            ['soporte-tecnico', 'Soporte técnico', 'Asistencia técnica para equipos y herramientas digitales.'],
            ['desarrollo-web', 'Desarrollo web', 'Creación y mantenimiento de sitios web.'],
            ['software-servicios-digitales', 'Software y servicios digitales', 'Desarrollo de software y otros servicios freelance.'],
        ];

        foreach ($categories as $index => [$slug, $name, $description]) {
            // No reactiva, renombra ni sobrescribe categorías que ya existan.
            ServiceCategory::firstOrCreate(['slug' => $slug], [
                'name' => $name, 'description' => $description, 'is_active' => true, 'sort_order' => ($index + 1) * 10,
            ]);
        }
    }
}
