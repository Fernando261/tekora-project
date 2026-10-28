<?php

namespace App\Http\Requests;

use App\Enums\InquiryAction;
use Illuminate\Foundation\Http\FormRequest;

class DecideInquiryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $action = InquiryAction::from($this->route()->defaults['decision']);

        return $this->user('web')?->can($action->value, $this->route('inquiry')) ?? false;
    }

    public function rules(): array
    {
        return StoreInquiryRequest::protectedFields() + ['message' => ['prohibited'], 'action' => ['prohibited'], 'decision' => ['prohibited']];
    }

    public function messages(): array
    {
        return ['prohibited' => 'Utiliza únicamente la acción disponible. No puedes cambiar datos, propiedad ni estados desde este formulario.'];
    }
}
