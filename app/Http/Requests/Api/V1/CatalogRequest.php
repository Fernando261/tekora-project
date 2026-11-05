<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\ServiceModality;
use Illuminate\Validation\Rule;

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
            default => [],
        };
    }
}
