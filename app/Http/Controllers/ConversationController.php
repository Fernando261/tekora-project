<?php

namespace App\Http\Controllers;

use App\Exceptions\InquiryReadFailure;
use App\Http\Requests\MarkInquiryMessagesReadRequest;
use App\Models\ServiceInquiry;
use App\Services\InquiryReadTracking;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ConversationController extends Controller
{
    public function index(Request $request, InquiryReadTracking $tracking)
    {
        $user = $request->user('web');
        Gate::forUser($user)->authorize('conversations', ServiceInquiry::class);
        $request->validate(['page' => ['sometimes', 'integer', 'min:1', 'max:10000']]);
        $conversations = $tracking->withUnreadCount($tracking->conversations($user)->select([
            'id', 'service_id', 'client_user_id', 'service_title', 'status', 'created_at', 'updated_at']), $user)
            ->withMax('messages as latest_message_id', 'id')->orderByRaw('CASE WHEN unread_messages_count > 0 THEN 0 ELSE 1 END')
            ->orderByDesc('latest_message_id')->orderByDesc('id')->paginate(12);

        return view('conversations.index', compact('conversations'));
    }

    public function read(MarkInquiryMessagesReadRequest $request, ServiceInquiry $inquiry, InquiryReadTracking $tracking)
    {
        $detailUrl = route('inquiries.show', $inquiry).'#conversacion';
        try {
            $tracking->mark($request->user('web'), $inquiry->id, $request->validated('read_token'));

            return redirect()->to($detailUrl, 303)->with('success', 'Lectura actualizada.');
        } catch (InquiryReadFailure $exception) {
            if ($request->expectsJson()) return response()->json(['message' => $exception->getMessage()], $exception->status);

            return response()->view('conversations.read-error', ['message' => $exception->getMessage(), 'detailUrl' => $detailUrl], $exception->status);
        }
    }
}
