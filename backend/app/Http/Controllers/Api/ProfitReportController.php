<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Finance\FinancialLedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfitReportController extends Controller
{
    public function profitBreakdown(Request $request): JsonResponse
    {
        $filters = array_filter([
            'from' => $request->query('from'),
            'to' => $request->query('to'),
            'brand_id' => $request->query('brand_id') ? (int) $request->query('brand_id') : null,
            'service_category' => $request->query('service_category'),
            'source' => $request->query('source'),
            'pm_id' => $request->query('pm_id') ? (int) $request->query('pm_id') : null,
            'contractor_id' => $request->query('contractor_id') ? (int) $request->query('contractor_id') : null,
            'basis' => $request->query('basis', 'cash'),
        ], fn ($v) => $v !== null && $v !== '');

        $ledger = app(FinancialLedgerService::class);
        $summary = $ledger->summary($filters);
        $projected = $ledger->drilldown('projected_profit', $filters);
        $incomplete = $ledger->drilldown('incomplete_cost_data', $filters);
        $breakdown = $ledger->drilldown('revenue_jobs_breakdown', $filters);

        return response()->json([
            'refreshed_at' => $summary['refreshed_at'],
            'filters' => $summary['filters'],
            'labels' => $summary['labels'],
            'projected_profit' => $summary['projected_profit'],
            'realized_profit' => $summary['realized_profit'],
            'collected_revenue' => $summary['collected_revenue'],
            'accounts_receivable' => $summary['accounts_receivable'],
            'total_profit' => $summary['projected_profit'], // alias — labelled Projected Profit in UI
            'total_jobs' => $summary['counts']['approved_quotes_complete'],
            'quotes' => $projected['records'],
            'incomplete_cost_quotes' => $incomplete['records'],
            'incomplete_cost_quote_count' => $summary['incomplete_cost_quote_count'],
            'revenue_jobs_breakdown' => $breakdown['records'],
        ]);
    }
}
