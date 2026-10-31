<?php

namespace App\Http\Requests;

use App\Enums\Permission;
use App\Support\AvailabilityInput;
use Illuminate\Foundation\Http\FormRequest;

class ProviderAvailabilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('web')?->can(Permission::ManageProviderProfile->value) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->replace(AvailabilityInput::normalize($this->all()));
    }

    public function rules(): array
    {
        return AvailabilityInput::rules();
    }

    public function messages(): array
    {
        return AvailabilityInput::messages();
    }

    public function withValidator($validator): void
    {
        $validator->after(fn ($validator) => AvailabilityInput::checkIntervals($validator));
    }
}
