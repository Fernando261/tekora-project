<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'age' => ['required', 'integer', 'min:1'],
            'gender' => ['required', 'string', 'max:255'],
            'email' => ['bail', 'required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:6'],
            'password_confirmation' => ['sometimes', 'required', 'same:password'],
            'intent' => ['sometimes', 'required', 'string', Rule::in(['client', 'provider', 'both'])],
        ];
    }

    public function messages(): array
    {
        return [
            'required' => 'Completa el campo :attribute.',
            'string' => 'El campo :attribute debe ser texto.',
            'max' => 'El campo :attribute supera el tamaño permitido.',
            'age.integer' => 'Escribe tu edad como un número entero.',
            'age.min' => 'La edad debe ser mayor que cero.',
            'email.email' => 'Escribe un correo electrónico válido.',
            'email.unique' => 'Ya existe una cuenta central con este correo. Inicia sesión o recupera tu contraseña.',
            'password.min' => 'Utiliza al menos 6 caracteres en la contraseña.',
            'password_confirmation.same' => 'La confirmación no coincide con la contraseña.',
            'intent.in' => 'Elige contratar, ofrecer servicios o ambas opciones.',
        ];
    }

    public function attributes(): array
    {
        return ['name' => 'nombre', 'age' => 'edad', 'gender' => 'género', 'email' => 'correo',
            'password' => 'contraseña', 'password_confirmation' => 'confirmación', 'intent' => 'uso de la cuenta'];
    }
}
