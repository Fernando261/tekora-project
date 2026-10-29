<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class InquiryNotificationController extends Controller
{
    public function index(Request $request)
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);
        $notifications = $request->user('web')->inquiryNotifications()->with('inquiry:id,service_id,client_user_id')
            ->orderByDesc('created_at')->orderByDesc('id')->paginate(12);

        return view('notifications.index', compact('notifications'));
    }

    public function read(Request $request, string $notification)
    {
        // Buscar dentro de la cuenta evita revelar si un identificador ajeno existe.
        $notice = $request->user('web')->inquiryNotifications()->findOrFail($notification);
        $request->user('web')->inquiryNotifications()->whereKey($notice->id)->unread()->update(['read_at' => now()]);

        return redirect()->route('notifications.index', [], 303)->with('success', 'Notificación marcada como leída.');
    }
}
