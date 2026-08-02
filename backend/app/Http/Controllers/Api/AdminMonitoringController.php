<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Alert;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Monitoring\AlertDispatcher;
use App\Services\Monitoring\FailedJobMonitoringService;
use App\Services\Monitoring\MonitoringSummaryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Milestone 6A.4 — Owner System Health / monitoring control plane.
 */
class AdminMonitoringController extends Controller
{
    public function summary(Request $request, MonitoringSummaryService $summary): JsonResponse
    {
        $hours = max(1, min(168, (int) $request->get('window_hours', config('monitoring.summary_window_hours', 24))));
        $data = $summary->summarize(now()->subHours($hours));
        $data['window_hours'] = $hours;

        return response()->json($data);
    }

    public function failedJobs(Request $request, FailedJobMonitoringService $jobs): JsonResponse
    {
        $perPage = min(100, max(1, (int) $request->get('per_page', 25)));

        return response()->json($jobs->paginate($perPage));
    }

    public function retryFailedJob(Request $request, int $id, FailedJobMonitoringService $jobs): JsonResponse
    {
        try {
            $result = $jobs->retry($id);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }

        /** @var User $actor */
        $actor = $request->user();
        AuditLog::create([
            'user_id' => $actor->id,
            'user_role' => $actor->role,
            'object_type' => 'failed_job',
            'object_id' => $id,
            'action_type' => 'monitoring_failed_job_retry',
            'previous_value' => ['uuid' => $result['uuid'] ?? null],
            'new_value' => $result,
            'reason' => 'Owner retried failed job via System Health',
            'created_at' => now(),
        ]);

        return response()->json([
            'message' => 'Retry queued.',
            'result' => $result,
        ]);
    }

    public function dismissFailedJob(Request $request, int $id, FailedJobMonitoringService $jobs): JsonResponse
    {
        try {
            $snapshot = $jobs->dismiss($id);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }

        /** @var User $actor */
        $actor = $request->user();
        AuditLog::create([
            'user_id' => $actor->id,
            'user_role' => $actor->role,
            'object_type' => 'failed_job',
            'object_id' => $id,
            'action_type' => 'monitoring_failed_job_dismiss',
            'previous_value' => $snapshot,
            'new_value' => ['deleted' => true],
            'reason' => 'Owner dismissed failed job via System Health',
            'created_at' => now(),
        ]);

        return response()->json([
            'message' => 'Failed job dismissed.',
            'id' => $id,
        ]);
    }

    public function alerts(Request $request): JsonResponse
    {
        $query = Alert::query()->orderByDesc('id');

        if ($request->filled('severity')) {
            $query->where('severity', $request->string('severity')->toString());
        }
        if ($request->has('acknowledged')) {
            $ack = filter_var($request->get('acknowledged'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($ack === true) {
                $query->whereNotNull('acknowledged_at');
            } elseif ($ack === false) {
                $query->whereNull('acknowledged_at');
            }
        }

        $perPage = min(100, max(1, (int) $request->get('per_page', 25)));

        return response()->json($query->paginate($perPage));
    }

    public function acknowledgeAlert(Request $request, int $id): JsonResponse
    {
        $alert = Alert::query()->find($id);
        if (! $alert) {
            return response()->json(['message' => 'Alert not found.'], 404);
        }

        if ($alert->acknowledged_at) {
            return response()->json([
                'message' => 'Already acknowledged.',
                'alert' => $alert,
            ]);
        }

        /** @var User $actor */
        $actor = $request->user();
        $alert->acknowledged_at = now();
        $alert->acknowledged_by = $actor->id;
        $alert->save();

        AuditLog::create([
            'user_id' => $actor->id,
            'user_role' => $actor->role,
            'object_type' => 'alert',
            'object_id' => $alert->id,
            'action_type' => 'monitoring_alert_acknowledged',
            'previous_value' => ['acknowledged_at' => null],
            'new_value' => [
                'acknowledged_at' => optional($alert->acknowledged_at)?->toIso8601String(),
                'acknowledged_by' => $actor->id,
            ],
            'reason' => 'Owner acknowledged alert via System Health',
            'created_at' => now(),
        ]);

        return response()->json([
            'message' => 'Alert acknowledged.',
            'alert' => $alert,
        ]);
    }

    /** Convenience for tests / future admin tools — not required by UI this phase. */
    public function dispatchTestAlert(Request $request, AlertDispatcher $dispatcher): JsonResponse
    {
        $data = $request->validate([
            'severity' => ['required', 'string', 'max:32'],
            'message' => ['required', 'string', 'max:1000'],
        ]);

        $alert = $dispatcher->dispatch($data['severity'], $data['message'], [
            'source' => 'admin_test',
        ]);

        return response()->json(['alert' => $alert], 201);
    }
}
