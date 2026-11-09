<?php

namespace App\Http\Controllers;

use App\Exceptions\ClientDashboardFailure;
use App\Models\ProviderProfile;
use App\Services\ClientDashboardSummary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class AccountController extends Controller
{
    public function index(Request $request)
    {
        return view('account.index', ['user' => $request->user('web')]);
    }

    public function client(Request $request, ClientDashboardSummary $summary)
    {
        try {
            return DB::transaction(fn () => response()->view('account.client', [
                'user' => $request->user('web'), 'summary' => $summary->build($request->user('web'), $request->query()),
            ]), 3);
        } catch (ClientDashboardFailure $exception) {
            return $exception->render($request);
        } catch (\Throwable $exception) {
            for ($cause = $exception; $cause; $cause = $cause->getPrevious()) {
                if ($cause instanceof \PDOException) {
                    return (new ClientDashboardFailure)->render($request);
                }
            }
            throw $exception;
        }
    }

    public function activate()
    {
        return view('account.activate-provider');
    }

    public function provider(Request $request)
    {
        $user = $request->user('web');
        $profile = $user->providerProfile()->first();
        Gate::forUser($user)->authorize($profile ? 'update' : 'create', $profile ?? ProviderProfile::class);

        return view('account.provider', compact('user', 'profile'));
    }
}
