<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreInquiryMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('web')?->can('sendMessage', $this->route('inquiry')) ?? false;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('body'))) {
            $this->merge(['body' => trim(str_replace(["\r\n", "\r"], "\n", $this->input('body')))]);
        }
    }

    public function rules(): array
    {
        return ['body' => ['required', 'string', 'max:2000'], 'idempotency_key' => ['required', 'uuid']]
            + StoreInquiryRequest::protectedFields()
            + array_fill_keys(['author_user_id', 'sender_id', 'recipient_id', 'inquiry_id', 'service_inquiry_id',
                'message', 'event', 'read_at', 'url', 'files', 'file', 'image'], ['prohibited']);
    }

    public function messages(): array
    {
        return ['body.required' => 'Escribe un mensaje antes de enviarlo.',
            'body.string' => 'El mensaje debe contener texto.',
            'body.max' => 'El mensaje admite hasta 2000 caracteres.',
            'idempotency_key.*' => 'El identificador del envío no es válido. Abre de nuevo la solicitud.',
            'prohibited' => 'Este formulario solo admite texto y su identificador de envío.'];
    }
}
