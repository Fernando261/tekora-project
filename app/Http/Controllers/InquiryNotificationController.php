<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Exceptions\NotificationFailure;
use App\Services\NotificationInbox;
use Illuminate\Support\Facades\DB;

class InquiryNotificationController extends Controller
{
    public function index(Request $request)
    {
        return $this->safely($request, function () use ($request) {
            $page = \Illuminate\Support\Facades\Validator::make(['page' => $request->query('page', 1)], ['page' => ['required', 'integer', 'min:1', 'max:10000']]);
            if ($page->fails()) return (new NotificationFailure('La página de notificaciones no es válida.', 422))->render($request);

            return DB::transaction(function () use ($request, $page) {
                $notifications = app(NotificationInbox::class)->listing($request->user('web'), (int) $page->validated()['page']);

                return response()->view('notifications.index', compact('notifications'));
            }, 3);
        });
    }

    public function read(Request $request, string $notification)
    {
        // Buscar dentro de la cuenta evita revelar si un identificador ajeno existe.
        return $this->safely($request, function () use ($request, $notification) {
            $notice = $request->user('web')->inquiryNotifications()->findOrFail($notification);
            $request->user('web')->inquiryNotifications()->whereKey($notice->id)->unread()->update(['read_at' => now()]);

            return redirect()->route('notifications.index', [], 303)->with('success', 'Notificación marcada como leída.');
        });
    }

    public function readActivity(Request $request, string $notification)
    {
        return $this->safely($request, function () use ($request, $notification) {
            app(NotificationInbox::class)->readActivity($request->user('web'), $notification, $request->except('_token', '_method'));

            return redirect()->route('notifications.index', [], 303)->with('success', 'Notificación marcada como leída.');
        });
    }

    private function safely(Request $request, callable $operation)
    {
        try { return $operation(); }
        catch (NotificationFailure $exception) { return $exception->render($request); }
        catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) { return (new NotificationFailure('La notificación no está disponible.', 404))->render($request); }
        catch (\Illuminate\Auth\Access\AuthorizationException $exception) { return (new NotificationFailure('No tienes permiso para consultar esta notificación.', 403))->render($request); }
        catch (\Throwable $exception) {
            for ($cause = $exception; $cause; $cause = $cause->getPrevious()) {
                if ($cause instanceof NotificationFailure) return $cause->render($request);
                if ($cause instanceof \PDOException) {
                    $code = ((int) ($cause->errorInfo[1] ?? 0)) & 255;
                    if (! $request->isMethodSafe() && in_array($code, [5, 6], true)) return (new NotificationFailure('Hay otra lectura en curso. Inténtalo de nuevo.', 409))->render($request);

                    return (new NotificationFailure)->render($request);
                }
            }
            throw $exception;
        }
    }
}
