<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\NotificationFailure;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\NotificationRequest;
use App\Http\Resources\Api\V1\ApiCollection;
use App\Http\Resources\Api\V1\NotificationResource;
use App\Services\NotificationInbox;
use App\Support\Api\ApiError;

final class NotificationsController extends Controller
{
    public function index(NotificationRequest $request, NotificationInbox $inbox)
    {
        return $this->run(fn () => new ApiCollection($inbox->clientListing($request->user('sanctum'), $request->integer('page', 1),
            $request->integer('per_page', 12))->through(fn ($notice) => new NotificationResource($notice))));
    }

    public function unreadCount(NotificationRequest $request, NotificationInbox $inbox)
    {
        return response()->json(['data' => ['unread_count' => $inbox->clientUnreadCount($request->user('sanctum'))]]);
    }

    public function read(NotificationRequest $request, string $source_type, string $id, NotificationInbox $inbox)
    {
        return $this->run(fn () => new NotificationResource($inbox->readClient($request->user('sanctum'), $source_type, $id)));
    }

    private function run(callable $operation)
    {
        try {
            return $operation();
        } catch (NotificationFailure $exception) {
            return ApiError::from($exception);
        }
    }
}
