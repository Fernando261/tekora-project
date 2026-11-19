<?php

namespace App\Http\Requests\Api\V1;

use App\Services\MobileClientOperations;

final class JourneyRequest extends ApiRequest
{
    public function authorize(): bool
    {
        app(MobileClientOperations::class)->inquiry($this->user('sanctum'), (int) $this->route('inquiry'));

        return true;
    }

    public function rules(): array
    {
        return [];
    }
}
