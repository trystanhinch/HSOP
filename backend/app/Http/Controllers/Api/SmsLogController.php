<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SmsLog;
use App\Services\Messaging\DeliveryRetryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SmsLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = SmsLog::with([
            'user:id,name,email,phone',
            'job:id,address,customer_id,pm_id',
            'lead:id,contact_name,brand_id',
            'brand:id,company_name',
        ]);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('job_id')) {
            $query->where('related_job_id', $request->job_id);
        }
        if ($request->filled('lead_id')) {
            $query->where('related_lead_id', $request->lead_id);
        }
        if ($request->filled('brand_id')) {
            $query->where('brand_id', $request->brand_id);
        }
        if ($request->filled('trigger_event')) {
            $query->where('trigger_event', $request->trigger_event);
        }
        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->to);
        }
        if ($request->boolean('include_test_data')) {
            $query = SmsLog::withTestData()->with([
                'user:id,name,email,phone',
                'job:id,address,customer_id,pm_id',
                'lead:id,contact_name,brand_id',
                'brand:id,company_name',
            ]);
            // re-apply filters on withTestData base
            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }
        }

        $metrics = [
            'production_sent_30d' => SmsLog::query()->where('status', 'sent')->where('created_at', '>=', now()->subDays(30))->count(),
            'production_failed_30d' => SmsLog::query()->whereIn('status', ['failed', 'provider_unavailable'])->where('created_at', '>=', now()->subDays(30))->count(),
            'test_excluded' => true,
        ];

        return response()->json([
            'data' => $query->latest()->paginate(30),
            'metrics' => $metrics,
        ]);
    }

    public function retry(Request $request, SmsLog $smsLog, DeliveryRetryService $retry): JsonResponse
    {
        $data = $request->validate([
            'phone' => 'nullable|string|max:32',
        ]);

        $result = $retry->retrySms($smsLog, $request->user(), $data);

        return response()->json($result);
    }
}
