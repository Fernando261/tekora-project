<?php

namespace App\Http\Requests;

use App\Enums\InquiryStatus;
use App\Enums\Permission;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class InquiryIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        $permission = $this->routeIs('inquiries.received') ? Permission::ManageReceivedInquiries : Permission::ManageOwnInquiries;

        return $this->user('web')?->can($permission->value) ?? false;
    }

    public function rules(): array
    {
        return ['status' => ['nullable', Rule::enum(InquiryStatus::class)], 'page' => ['sometimes', 'integer', 'min:1', 'max:10000']];
    }

    public function messages(): array
    {
        return ['status.Illuminate\Validation\Rules\Enum' => 'Elige un estado válido.', 'page.*' => 'La página solicitada no es válida.'];
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->view('inquiries.invalid-filters', ['messages' => $validator->errors()->all(),
            'listRoute' => $this->routeIs('inquiries.received') ? 'inquiries.received' : 'inquiries.index'], 422));
    }
}
