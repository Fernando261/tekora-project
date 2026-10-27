<?php

namespace App\Http\Requests;

use App\Enums\Permission;
use App\Enums\RoleName;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBusinessRolesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('web')?->can(Permission::ManageAccountRoles->value) ?? false;
    }

    public function rules(): array
    {
        return [
            'roles' => ['present', 'array', 'max:2'],
            'roles.*' => ['required', 'string', 'distinct', Rule::in(RoleName::businessValues())],
            'user_id' => ['prohibited'],
            'is_admin' => ['prohibited'],
        ];
    }
}
