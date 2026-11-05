<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\InquiryAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\OperationRequest;
use App\Http\Resources\Api\V1\ApiCollection;
use App\Http\Resources\Api\V1\ConversationResource;
use App\Http\Resources\Api\V1\InquiryResource;
use App\Http\Resources\Api\V1\MessageResource;
use App\Http\Resources\Api\V1\ReservationOptionsResource;
use App\Http\Resources\Api\V1\ReservationResource;
use App\Services\InquiryMessaging;
use App\Services\InquiryReadTracking;
use App\Services\InquiryWorkflow;
use App\Services\MobileClientOperations;
use App\Services\ReservationWorkflow;
use Illuminate\Support\Facades\DB;

final class OperationsController extends Controller
{
    public function inquiries(OperationRequest $request, MobileClientOperations $scope): ApiCollection
    {
        $query = $scope->inquiries($request->user('sanctum'));
        if ($request->has('status')) {
            $query->where('status', $request->validated('status'));
        }

        return new ApiCollection($query->orderByDesc('id')->paginate($request->validated('per_page', 12))
            ->through(fn ($row) => new InquiryResource($row)));
    }

    public function open(OperationRequest $request, int $service, InquiryWorkflow $workflow)
    {
        $result = $workflow->open($request->user('sanctum'), $service, $request->validated('message'));

        return (new InquiryResource($result['inquiry']))->response()->setStatusCode($result['created'] ? 201 : 200);
    }

    public function inquiry(OperationRequest $request): InquiryResource
    {
        return new InquiryResource($request->attributes->get('mobile_inquiry'));
    }

    public function cancelInquiry(OperationRequest $request, int $inquiry, InquiryWorkflow $workflow): InquiryResource
    {
        return new InquiryResource($workflow->decide($request->user('sanctum'), $inquiry, InquiryAction::Cancel));
    }

    public function conversations(OperationRequest $request, InquiryReadTracking $tracking): ApiCollection
    {
        $user = $request->user('sanctum');
        $query = $tracking->conversations($user)->where('service_inquiries.client_user_id', $user->id);
        $page = $tracking->withUnreadCount($query, $user)->withMax('messages as latest_message_id', 'id')
            ->orderByRaw('CASE WHEN unread_messages_count > 0 THEN 0 ELSE 1 END')
            ->orderByDesc('latest_message_id')->orderByDesc('id')->paginate($request->validated('per_page', 12));

        return new ApiCollection($page->through(fn ($row) => new ConversationResource($row)));
    }

    public function messages(OperationRequest $request, InquiryReadTracking $tracking)
    {
        return DB::transaction(function () use ($request, $tracking) {
            $user = $request->user('sanctum');
            $inquiry = $request->attributes->get('mobile_inquiry');
            $page = $inquiry->messages()->orderByDesc('created_at')->orderByDesc('id')
                ->cursorPaginate(20, ['id', 'service_inquiry_id', 'author_user_id', 'body', 'created_at'], 'messages_cursor');
            $token = $tracking->tokenFor($user, $inquiry, $page->getCollection());

            return response()->json(['data' => MessageResource::collection($page->getCollection())->resolve($request),
                'meta' => ['per_page' => 20, 'next_cursor' => $page->nextCursor()?->encode(),
                    'previous_cursor' => $page->previousCursor()?->encode(), 'read_token' => $token,
                    'last_read_message_id' => $tracking->markerFor($user, $inquiry),
                    'unread_messages_count' => $tracking->unreadFor($user, $inquiry)]]);
        }, 3);
    }

    public function send(OperationRequest $request, int $inquiry, InquiryMessaging $messaging)
    {
        $result = $messaging->send($request->user('sanctum'), $inquiry, $request->validated('body'), $request->validated('idempotency_key'));

        return (new MessageResource($result['message']))->response()->setStatusCode($result['created'] ? 201 : 200);
    }

    public function read(OperationRequest $request, int $inquiry, InquiryReadTracking $tracking)
    {
        $marker = $tracking->mark($request->user('sanctum'), $inquiry, $request->validated('read_token'));

        return response()->json(['data' => ['inquiry_id' => $inquiry, 'last_read_message_id' => $marker->last_read_message_id]]);
    }

    public function options(OperationRequest $request, int $inquiry, ReservationWorkflow $workflow): ReservationOptionsResource
    {
        return new ReservationOptionsResource($workflow->options($request->user('sanctum'), $inquiry));
    }

    public function propose(OperationRequest $request, int $inquiry, ReservationWorkflow $workflow)
    {
        $result = $workflow->propose($request->user('sanctum'), $inquiry, $request->validated());

        return (new ReservationResource($result['reservation']))->response()->setStatusCode($result['created'] ? 201 : 200);
    }

    public function reservations(OperationRequest $request, MobileClientOperations $scope): ApiCollection
    {
        $query = $scope->reservations($request->user('sanctum'));
        if ($request->has('status')) {
            $query->where('status', $request->validated('status'));
        }

        return new ApiCollection($query->orderByDesc('id')->paginate($request->validated('per_page', 12))
            ->through(fn ($row) => new ReservationResource($row)));
    }

    public function reservation(OperationRequest $request): ReservationResource
    {
        return new ReservationResource($request->attributes->get('mobile_reservation'));
    }

    public function cancelReservation(OperationRequest $request, int $reservation, ReservationWorkflow $workflow): ReservationResource
    {
        return new ReservationResource($workflow->decide($request->user('sanctum'), $reservation, 'cancel', $request->validated()));
    }
}
