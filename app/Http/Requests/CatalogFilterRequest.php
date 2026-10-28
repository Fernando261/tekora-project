<?php

namespace App\Http\Requests;

use App\Enums\ServiceModality;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class CatalogFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:120'],
            'category' => ['nullable', 'string', 'max:120', Rule::exists('service_categories', 'slug')->where('is_active', true)],
            'modality' => ['nullable', Rule::enum(ServiceModality::class)],
            'page' => ['sometimes', 'integer', 'min:1', 'max:10000'],
        ];
    }

    public function messages(): array
    {
        return [
            'q.max' => 'La búsqueda admite hasta 120 caracteres.',
            'q.string' => 'La búsqueda debe contener texto.',
            'category.exists' => 'La categoría seleccionada no está disponible.',
            'modality.Illuminate\Validation\Rules\Enum' => 'Selecciona una modalidad del catálogo.',
            'page.*' => 'La página solicitada no es válida.',
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        // Un filtro GET inválido no debe redirigir a su propia URL indefinidamente.
        throw new HttpResponseException(response()->view('catalog.invalid-filters', ['messages' => $validator->errors()->all()], 422));
    }
}
