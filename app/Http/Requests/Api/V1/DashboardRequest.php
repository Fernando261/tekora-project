<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Validation\Validator;

final class DashboardRequest extends ApiRequest
{
    public function rules(): array
    {
        return [];
    }

    public function withValidator(Validator $validator): void
    {
        parent::withValidator($validator);
        if ($this->getContent() !== '') {
            $validator->after(fn (Validator $validator) => $validator->errors()->add('request', 'El panel no admite un cuerpo de petición.'));
        }
    }
}
