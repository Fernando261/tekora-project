<?php

namespace App\Http\Requests\Api\V1;

use App\Payments\PricingInput;
use App\Services\MobileClientOperations;
use Illuminate\Support\Facades\DB;

final class CommercialRequest extends ApiRequest
{
    public function authorize(): bool
    {
        $scope = app(MobileClientOperations::class);
        $actor = $this->user('sanctum');
        if ($this->route('inquiry') !== null) {
            $scope->inquiry($actor, (int) $this->route('inquiry'));
        }
        if ($this->route('quote') !== null) {
            // Hide foreign IDs before validating any body, like other mobile operations.
            $row = DB::table('service_quotes')->where('id', strtolower($this->route('quote')))->where('client_user_id', $actor->id)->first(['service_inquiry_id']);
            abort_unless($row, 404);
            $scope->inquiry($actor, $row->service_inquiry_id);
        }
        if ($this->route('reservation') !== null) {
            $scope->reservation($actor, (int) $this->route('reservation'));
        }

        return true;
    }

    public function rules(): array
    {
        return match ($this->route()->getName()) {
            'api.v1.quotes.index' => ['page' => ['sometimes', 'integer', 'min:1', 'max:10000'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:50']],
            // Canonical UUID validation/normalization stays in PricingInput.
            'api.v1.quotes.issue' => ['idempotency_key' => ['present']],
            'api.v1.quotes.accept' => PricingInput::acknowledgementRules(),
            default => [],
        };
    }
}
