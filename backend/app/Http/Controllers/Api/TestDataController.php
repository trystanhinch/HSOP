<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\FlagTestDataJob;
use App\Services\TestData\FlagTestDataService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class TestDataController extends Controller
{
    public function summary(FlagTestDataService $service): JsonResponse
    {
        return response()->json([
            'counts' => $service->testCounts(),
            'last_run' => Cache::get('serviceop:flag_test_data:last_result'),
            'app_env' => config('app.env'),
        ]);
    }

    public function dryRun(FlagTestDataService $service): JsonResponse
    {
        $result = $service->run(apply: false);

        return response()->json($result);
    }

    public function apply(Request $request): JsonResponse
    {
        $data = $request->validate([
            'confirm' => ['required', 'accepted'],
        ]);

        FlagTestDataJob::dispatch(
            apply: true,
            requestedByUserId: $request->user()?->id,
        );

        // Also run synchronously when queue is sync (local/dev) — job still runs via dispatch.
        return response()->json([
            'message' => 'Test-data flag job queued. Refresh this tab in a moment for updated counts.',
            'queued' => true,
            'confirm' => (bool) $data['confirm'],
        ]);
    }
}
