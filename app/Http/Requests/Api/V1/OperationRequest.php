<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\InquiryStatus;
use App\Enums\ReservationStatus;
use App\Http\Requests\InquiryConversationRequest;
use App\Services\MobileClientOperations;
use Illuminate\Validation\Rule;

final class OperationRequest extends ApiRequest
{
    public function authorize(): bool
    {
        $scope = app(MobileClientOperations::class);
        $actor = $this->user('sanctum');
        // Antes de validar cuerpos: IDs ajenos e inexistentes tienen el mismo 404.
        if ($this->route('inquiry') !== null) {
            $this->attributes->set('mobile_inquiry', $scope->inquiry($actor, (int) $this->route('inquiry')));
        }
        if ($this->route('reservation') !== null) {
            $this->attributes->set('mobile_reservation', $scope->reservation($actor, (int) $this->route('reservation')));
        }

        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->route()->getName() === 'api.v1.messages.store' && is_string($this->input('body'))) {
            $this->merge(['body' => trim(str_replace(["\r\n", "\r"], "\n", $this->input('body')))]);
        }
    }

    public function rules(): array
    {
        $pages = ['page' => ['sometimes', 'integer', 'min:1', 'max:10000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50']];

        return match ($this->route()->getName()) {
            'api.v1.inquiries.index' => $pages + ['status' => ['sometimes', Rule::enum(InquiryStatus::class)]],
            'api.v1.conversations.index' => $pages,
            'api.v1.reservations.index' => $pages + ['status' => ['sometimes', Rule::enum(ReservationStatus::class)]],
            'api.v1.inquiries.store' => ['message' => ['required', 'string', 'min:10', 'max:2000']],
            // Reutiliza el formato/cotas del cursor web sin su autorización de sesión.
            'api.v1.messages.index' => (new InquiryConversationRequest)->rules(),
            'api.v1.messages.store' => ['body' => ['required', 'string', 'max:2000'],
                'idempotency_key' => ['required', 'string', 'uuid']],
            'api.v1.messages.read' => ['read_token' => ['required', 'string', 'max:2048']],
            // ReservationInput valida estos cuerpos en el workflow, sin mantener reglas paralelas.
            'api.v1.reservations.store' => array_fill_keys(['date', 'time', 'availability_revision', 'idempotency_key'], ['present']),
            'api.v1.reservations.cancel' => ['version' => ['present']],
            default => [],
        };
    }
}
