<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\ServiceModality;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class CatalogRequest extends ApiRequest
{
    public function rules(): array
    {
        $pagination = ['page' => ['sometimes', 'integer', 'min:1', 'max:10000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50']];

        return match ($this->route()->getName()) {
            'api.v1.services.index' => $pagination + [
                'q' => ['sometimes', 'string', 'max:120'],
                'category' => ['sometimes', 'string', 'max:120', Rule::exists('service_categories', 'slug')->where('is_active', true)],
                'modality' => ['sometimes', Rule::enum(ServiceModality::class)],
            ],
            'api.v1.providers.index' => $pagination + ['q' => ['sometimes', 'string', 'max:120']],
            'api.v1.providers.services.index' => $pagination,
            default => [],
        };
    }

    public function withValidator(Validator $validator): void
    {
        parent::withValidator($validator);
        if ($this->routeIs('api.v1.categories.index', 'api.v1.providers.services.index') && $this->getContent() !== '') {
            $validator->after(fn (Validator $validator) => $validator->errors()->add('request', 'Envía los parámetros de lectura en la consulta.'));
        }
    }
}
