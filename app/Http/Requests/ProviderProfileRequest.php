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
}
