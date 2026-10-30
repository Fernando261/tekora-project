<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MarkInquiryMessagesReadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('web')?->can('view', $this->route('inquiry')) ?? false;
    }

    public function rules(): array
    {
        return ['read_token' => ['required', 'string', 'max:2048']] + StoreInquiryRequest::protectedFields()
            + array_fill_keys(['inquiry_id', 'service_inquiry_id', 'author_user_id', 'recipient_id', 'last_read_message_id',
                'message_id', 'through', 'read_at', 'body', 'message', 'url'], ['prohibited']);
    }

    public function messages(): array
    {
        return ['read_token.*' => 'Abre de nuevo la conversación para marcar los mensajes mostrados.',
            'prohibited' => 'La cuenta y el límite de lectura se comprueban en el servidor.'];
    }
}
