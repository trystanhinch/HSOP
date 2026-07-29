<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmailLog;
use App\Services\Messaging\DeliveryRetryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = EmailLog::with([
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

        $metrics = [
            'production_sent_30d' => EmailLog::query()->where('status', 'sent')->where('created_at', '>=', now()->subDays(30))->count(),
            'production_failed_30d' => EmailLog::query()->whereIn('status', ['failed', 'provider_unavailable'])->where('created_at', '>=', now()->subDays(30))->count(),
            'test_excluded' => true,
        ];

        return response()->json([
            'data' => $query->latest()->paginate(30),
            'metrics' => $metrics,
        ]);
    }

    public function retry(Request $request, EmailLog $emailLog, DeliveryRetryService $retry): JsonResponse
    {
        $data = $request->validate([
            'email' => 'nullable|email|max:255',
        ]);

        $result = $retry->retryEmail($emailLog, $request->user(), $data);

        return response()->json($result);
    }
}
