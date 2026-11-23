<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\DashboardRequest;
use App\Services\ClientDashboardSummary;
use Illuminate\Http\JsonResponse;

final class DashboardController extends Controller
{
    public function __invoke(DashboardRequest $request, ClientDashboardSummary $summary): JsonResponse
    {
        return response()->json(['data' => $summary->forMobile($request->user('sanctum'))]);
    }
}
