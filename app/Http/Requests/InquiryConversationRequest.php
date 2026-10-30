<?php

namespace App\Http\Requests;

use DateTimeImmutable;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Pagination\Cursor;

class InquiryConversationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('web')?->can('view', $this->route('inquiry')) ?? false;
    }

    public function rules(): array
    {
        return ['messages_cursor' => ['nullable', 'string', 'max:1024', function ($attribute, $value, $fail) {
            try {
                $cursor = Cursor::fromEncoded($value);
                $date = $cursor?->parameter('created_at');
                $id = $cursor?->parameter('id');
                $validDate = is_string($date) ? DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $date) : false;
                if (! $cursor || ! $validDate || $validDate->format('Y-m-d H:i:s') !== $date
                    || filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                    $fail('La página de la conversación no es válida.');
                }
            } catch (\Throwable $exception) {
                $fail('La página de la conversación no es válida.');
            }
        }]];
    }

    protected function failedValidation(Validator $validator)
    {
        $message = 'La página de la conversación no es válida. Abre de nuevo la solicitud.';
        if ($this->expectsJson()) throw new HttpResponseException(response()->json(['message' => $message], 422));

        throw new HttpResponseException(response()->view('inquiries.message-error', ['message' => $message,
            'detailUrl' => route('inquiries.show', $this->route('inquiry')).'#conversacion'], 422));
    }
}
