<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Quote;
use Illuminate\Http\JsonResponse;

class ProfitReportController extends Controller
{
    public function profitBreakdown(): JsonResponse
    {
        $quotes = Quote::where('status', 'approved')
            ->with([
                'job:id,address,job_title,contractor_id',
                'job.contractor:id,name',
                'customer:id,name',
            ])
            ->select('id', 'job_id', 'customer_id', 'quote_number', 'contractor_base_price', 'customer_price_before_gst', 'hsop_markup', 'customer_total', 'accepted_at')
            ->latest('accepted_at')
            ->get();

        return response()->json([
            'quotes' => $quotes,
            'total_profit' => $quotes->sum('hsop_markup'),
            'total_jobs' => $quotes->count(),
        ]);
    }
}
