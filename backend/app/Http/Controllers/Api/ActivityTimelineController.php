<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Job;
use App\Models\Lead;
use App\Services\ActivityTimelineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ActivityTimelineController extends Controller
{
    public function __construct(protected ActivityTimelineService $timeline) {}

    public function indexForLead(Request $request, Lead $lead): JsonResponse
    {
        $this->authorizeLead($request, $lead);

        return response()->json([
            'timeline' => $this->timeline->forSubject($lead),
        ]);
    }

    public function storeForLead(Request $request, Lead $lead): JsonResponse
    {
        $this->authorizeLead($request, $lead);

        $data = $request->validate([
            'event_type' => 'required|string|max:100',
            'description' => 'required|string|max:2000',
            'metadata' => 'nullable|array',
        ]);

        $entry = $this->timeline->record(
            $lead,
            $data['event_type'],
            $data['description'],
            $request->user(),
            $data['metadata'] ?? null,
        );

        return response()->json($entry->load('actorUser:id,name,role'), 201);
    }

    public function indexForJob(Request $request, Job $job): JsonResponse
    {
        $this->authorizeJob($request, $job);

        return response()->json([
            'timeline' => $this->timeline->forSubject($job),
        ]);
    }

    public function storeForJob(Request $request, Job $job): JsonResponse
    {
        $this->authorizeJob($request, $job);

        $data = $request->validate([
            'event_type' => 'required|string|max:100',
            'description' => 'required|string|max:2000',
            'metadata' => 'nullable|array',
        ]);

        $entry = $this->timeline->record(
            $job,
            $data['event_type'],
            $data['description'],
            $request->user(),
            $data['metadata'] ?? null,
        );

        return response()->json($entry->load('actorUser:id,name,role'), 201);
    }

    protected function authorizeLead(Request $request, Lead $lead): void
    {
        $user = $request->user();
        if (! in_array($user->role, ['owner', 'pm'], true)) {
            abort(403, 'Unauthorized');
        }
        if ($user->role === 'pm' && $lead->assigned_pm_id !== $user->id) {
            abort(403, 'Unauthorized');
        }
    }

    protected function authorizeJob(Request $request, Job $job): void
    {
        $user = $request->user();
        if ($user->role === 'owner') {
            return;
        }
        if ($user->role === 'pm' && $job->pm_id === $user->id) {
            return;
        }
        if ($user->role === 'contractor' && $job->contractor_id === $user->id) {
            return;
        }
        if ($user->role === 'customer' && $job->customer_id === $user->id) {
            return;
        }
        abort(403, 'Unauthorized');
    }
}
