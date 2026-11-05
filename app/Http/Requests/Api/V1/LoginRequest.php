<?php

namespace App\Http\Requests\Api\V1;

final class LoginRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:1024'],
            'device_name' => ['required', 'string', 'max:80', 'regex:/^[^\p{C}<>]+$/u'],
        ];
    }
}
