<?php

namespace Tests\Support;

use App\Enums\RoleName;
use App\Models\Role;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\User;

trait CatalogFixtures
{
    protected function account(RoleName ...$roles): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::whereIn('slug', array_map(fn ($role) => $role->value, $roles))->pluck('id'));

        return $user;
    }

    protected function category(string $slug = 'soporte-tecnico'): ServiceCategory
    {
        return ServiceCategory::firstOrCreate(['slug' => $slug], ['name' => 'Categoría '.$slug, 'is_active' => true]);
    }

    protected function service(User $owner, array $attributes = []): Service
    {
        $profile = $owner->providerProfile()->firstOrCreate([], ['display_name' => 'Nombre público '.$owner->id,
            'headline' => 'Especialista en soporte', 'bio' => 'Experiencia pública del prestador.']);

        return $profile->services()->create($attributes + $this->serviceData());
    }

    protected function serviceData(array $attributes = []): array
    {
        return $attributes + ['title' => 'Soporte de computadoras', 'description' => 'Diagnóstico y mantenimiento de equipos.',
            'service_category_id' => $this->category()->id, 'modality' => 'remote', 'status' => 'published'];
    }
}
