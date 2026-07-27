<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Finance\FinancialLedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FinancialLedgerController extends Controller
{
    public function __construct(private readonly FinancialLedgerService $ledger) {}

    public function summary(Request $request): JsonResponse
    {
        if ($request->user()->role !== 'owner') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return response()->json($this->ledger->summary($this->filters($request)));
    }

    public function drilldown(Request $request): JsonResponse
    {
        if ($request->user()->role !== 'owner') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $metric = (string) $request->query('metric', '');
        if ($metric === '') {
            return response()->json(['message' => 'metric is required'], 422);
        }

        return response()->json($this->ledger->drilldown($metric, $this->filters($request)));
    }

    public function payoutGroups(Request $request): JsonResponse
    {
        if ($request->user()->role !== 'owner') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return response()->json([
            'refreshed_at' => now()->toIso8601String(),
            'groups' => $this->ledger->payoutGroups([
                'status' => $request->query('status'),
            ]),
        ]);
    }

    public function payoutJob(Request $request, int $jobId): JsonResponse
    {
        if ($request->user()->role !== 'owner') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return response()->json($this->ledger->payoutReconciliationForJob($jobId));
    }

    private function filters(Request $request): array
    {
        return array_filter([
            'from' => $request->query('from'),
            'to' => $request->query('to'),
            'brand_id' => $request->query('brand_id') ? (int) $request->query('brand_id') : null,
            'service_category' => $request->query('service_category'),
            'source' => $request->query('source'),
            'pm_id' => $request->query('pm_id') ? (int) $request->query('pm_id') : null,
            'contractor_id' => $request->query('contractor_id') ? (int) $request->query('contractor_id') : null,
            'basis' => $request->query('basis', 'cash'),
        ], fn ($v) => $v !== null && $v !== '');
    }
}
