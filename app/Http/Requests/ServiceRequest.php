<?php

namespace App\Http\Requests;

use App\Enums\ServiceModality;
use App\Enums\ServiceStatus;
use App\Models\Service;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $service = $this->route('service');

        return $service instanceof Service
            ? ($this->user('web')?->can('update', $service) ?? false)
            : ($this->user('web')?->can('create', Service::class) ?? false);
    }

    public static function detailRules(): array
    {
        return [
            'title' => ['required', 'string', 'max:160'],
            'description' => ['required', 'string', 'max:5000'],
            'service_category_id' => ['required', 'integer', Rule::exists('service_categories', 'id')->where('is_active', true)],
            'modality' => ['required', Rule::enum(ServiceModality::class)],
        ];
    }

    public static function protectedFields(): array
    {
        return array_fill_keys(['id', 'user_id', 'provider_profile_id', 'profesionista_id', 'roles', 'role', 'is_admin', 'permissions'], ['prohibited']);
    }

    public function rules(): array
    {
        return self::detailRules() + ['status' => ['required', Rule::enum(ServiceStatus::class)]] + self::protectedFields();
    }

    public function messages(): array
    {
        return [
            'required' => 'Completa el campo :attribute.',
            'string' => 'El campo :attribute debe contener texto.',
            'max' => 'El campo :attribute admite hasta :max caracteres.',
            'service_category_id.exists' => 'Elige una categoría activa del catálogo.',
            'service_category_id.integer' => 'Elige una categoría válida.',
            'modality.Illuminate\Validation\Rules\Enum' => 'Elige una modalidad válida.',
            'status.Illuminate\Validation\Rules\Enum' => 'Elige borrador, publicado o pausado.',
            'prohibited' => 'No puedes cambiar propiedad ni permisos desde este formulario.',
        ];
    }

    public function attributes(): array
    {
        return ['title' => 'título', 'description' => 'descripción', 'service_category_id' => 'categoría',
            'modality' => 'modalidad', 'status' => 'estado'];
    }
}
