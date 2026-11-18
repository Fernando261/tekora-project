<?php

namespace App\Http\Requests\Api\V1;

use App\Services\NotificationInbox;

final class NotificationRequest extends ApiRequest
{
    public function authorize(): bool
    {
        if ($this->route('source_type') !== null) {
            app(NotificationInbox::class)->clientNotice($this->user('sanctum'), $this->route('source_type'), $this->route('id'));
        }

        return true;
    }

    public function rules(): array
    {
        return $this->routeIs('api.v1.notifications.index')
            ? ['page' => ['sometimes', 'integer', 'min:1', 'max:10000'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:50']]
            : [];
    }
}
