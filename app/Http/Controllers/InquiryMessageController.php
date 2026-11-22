<?php

namespace App\Http\Controllers;

use App\Exceptions\InquiryMessageConflict;
use App\Exceptions\InquiryMessageUnavailable;
use App\Http\Requests\StoreInquiryMessageRequest;
use App\Models\ServiceInquiry;
use App\Rules\WithoutExternalContact;
use App\Services\InquiryMessaging;
use Illuminate\Support\Facades\Validator;

class InquiryMessageController extends Controller
{
    public function store(StoreInquiryMessageRequest $request, ServiceInquiry $inquiry, InquiryMessaging $messaging)
    {
        $detailUrl = route('inquiries.show', $inquiry).'#conversacion';
        try {
            $result = $messaging->send(
                $request->user('web'),
                $inquiry->id,
                $request->validated('body'),
                $request->validated('idempotency_key'),
                static fn (string $body) => Validator::validate(['body' => $body], ['body' => [new WithoutExternalContact]]),
            );

            return redirect()->to($detailUrl, 303)->with($result['created'] ? 'success' : 'info',
                $result['created'] ? 'Mensaje enviado.' : 'Este mensaje ya se había enviado. Se conserva el original.');
        } catch (InquiryMessageConflict|InquiryMessageUnavailable $exception) {
            $status = $exception instanceof InquiryMessageConflict ? 409 : 503;
            if ($request->expectsJson()) {
                return response()->json(['message' => $exception->getMessage()], $status);
            }

            return response()->view('inquiries.message-error', ['message' => $exception->getMessage(), 'detailUrl' => $detailUrl], $status);
        }
    }
}
