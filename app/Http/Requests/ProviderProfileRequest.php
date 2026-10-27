<?php

namespace App\Http\Requests;

use App\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;

class ProviderProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('web')?->can(Permission::ManageProviderProfile->value) ?? false;
    }

    public function rules(): array
    {
        return [
            'display_name' => ['required', 'string', 'max:255'],
            'headline' => ['nullable', 'string', 'max:160'],
            'bio' => ['nullable', 'string', 'max:5000'],
            'user_id' => ['prohibited'],
            'profesionista_id' => ['prohibited'],
            'roles' => ['prohibited'],
            'is_admin' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'display_name.required' => 'Escribe el nombre público de tu perfil.',
            'display_name.max' => 'El nombre público admite hasta 255 caracteres.',
            'headline.max' => 'El titular profesional admite hasta 160 caracteres.',
            'bio.max' => 'La biografía admite hasta 5000 caracteres.',
            'string' => 'El campo :attribute debe contener texto.',
            'prohibited' => 'El campo :attribute no se puede modificar desde este formulario.',
        ];
    }
}
