<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiOpsReport;
use App\Services\Reporting\AiOpsReportService;
use App\Services\Reporting\SourcePerformanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OpsReportController extends Controller
{
    public function sourcePerformance(Request $request, SourcePerformanceService $service): JsonResponse
    {
        if ($request->user()->role !== 'owner') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return response()->json($service->summary());
    }

    public function index(Request $request): JsonResponse
    {
        if ($request->user()->role !== 'owner') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return response()->json(
            AiOpsReport::latest('report_date')->latest('id')->paginate(20)
        );
    }

    public function generate(Request $request, AiOpsReportService $reports): JsonResponse
    {
        if ($request->user()->role !== 'owner') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $data = $request->validate([
            'period' => 'nullable|in:daily,weekly',
        ]);

        $report = $reports->generate($data['period'] ?? 'daily');

        return response()->json(['message' => 'Report generated', 'report' => $report], 201);
    }

    public function show(Request $request, AiOpsReport $aiOpsReport): JsonResponse
    {
        if ($request->user()->role !== 'owner') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return response()->json($aiOpsReport);
    }
}
