<?php

namespace App\Http\Requests\Api\V1;

use App\Support\ProfileInput;
use Closure;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

final class IdentityRequest extends ApiRequest
{
    public function withValidator(Validator $validator): void
    {
        parent::withValidator($validator);
        $validator->after(function (Validator $validator) {
            $mediaType = strtolower(trim(explode(';', $this->header('Content-Type', ''), 2)[0]));
            $validType = $this->routeIs('api.v1.profile.photo.store')
                ? $mediaType === 'multipart/form-data'
                : $this->isJson();
            if (! $validType || $this->query() !== []) {
                $validator->errors()->add('request', 'Envía los datos en el cuerpo con el formato indicado.');
            }
        });
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => Str::lower(trim($this->input('email')))]);
        }
    }

    public function rules(): array
    {
        $email = ['email' => ['required', 'string', 'ascii', 'email', 'max:255']];
        $confirmation = $email + [
            'token' => ['required', 'string', 'max:4096'],
            'password' => ['bail', 'required', 'string', 'min:8', 'confirmed', static function (string $attribute, string $value, Closure $fail): void {
                if (strlen($value) > 72 || str_contains($value, "\0")) {
                    $fail('La contraseña debe tener como máximo 72 bytes y no puede contener caracteres nulos.');
                }
            }],
            'password_confirmation' => ['required', 'string', 'max:72'],
        ];

        return match ($this->route()->getName()) {
            'api.v1.auth.register', 'api.v1.auth.forgot-password' => $email,
            'api.v1.auth.register.confirm' => $confirmation + ['name' => ProfileInput::name(),
                'age' => ['required', 'integer:strict', 'min:1', 'max:255'], 'gender' => ['required', 'string', 'max:255']],
            'api.v1.auth.reset-password' => $confirmation,
            'api.v1.profile.update' => ['name' => ProfileInput::name()],
            'api.v1.profile.photo.store' => ['photo' => ProfileInput::photo()],
        };
    }
}
