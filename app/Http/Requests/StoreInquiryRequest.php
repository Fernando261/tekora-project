<?php

namespace App\Http\Requests;

use App\Models\ServiceInquiry;
use Illuminate\Foundation\Http\FormRequest;

class StoreInquiryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('web')?->can('create', [ServiceInquiry::class, $this->route('service')]) ?? false;
    }

    public static function protectedFields(): array
    {
        return array_fill_keys(['id', 'service_id', 'client_user_id', 'provider_profile_id', 'provider_user_id', 'user_id',
            'profesionista_id', 'roles', 'role', 'permissions', 'is_admin', 'status', 'service_title',
            'accepted_at', 'rejected_at', 'cancelled_at', 'completed_at', 'created_at', 'updated_at',
            'email', 'phone', 'address', 'attachments', 'payment_url'], ['prohibited']);
    }

    public function rules(): array
    {
        return ['message' => ['required', 'string', 'min:10', 'max:2000']] + self::protectedFields();
    }

    public function messages(): array
    {
        return ['message.required' => 'Describe brevemente lo que necesitas.',
            'message.string' => 'La descripción debe contener texto.',
            'message.min' => 'Escribe al menos 10 caracteres para explicar tu necesidad.',
            'message.max' => 'La descripción admite hasta 2000 caracteres.',
            'prohibited' => 'Este formulario no permite cambiar propiedad, estados, privilegios ni agregar datos de contacto o archivos.'];
    }
}
