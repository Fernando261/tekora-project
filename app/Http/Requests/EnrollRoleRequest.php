<?php

namespace App\Http\Requests;

use App\Enums\RoleName;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EnrollRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('web') !== null;
    }

    public function rules(): array
    {
        return [
            'role' => ['required', 'string', Rule::in(RoleName::businessValues())],
            'user_id' => ['prohibited'],
            'roles' => ['prohibited'],
            'is_admin' => ['prohibited'],
        ];
    }
}
