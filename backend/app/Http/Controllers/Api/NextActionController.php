<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Job;
use App\Models\Lead;
use App\Models\NextAction;
use App\Services\ActivityTimelineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NextActionController extends Controller
{
    public function __construct(protected ActivityTimelineService $timeline) {}

    public function showForLead(Request $request, Lead $lead): JsonResponse
    {
        $this->authorizeLead($request, $lead);

        return response()->json([
            'next_action' => $lead->pendingNextAction()->with('responsibleUser:id,name,role')->first(),
        ]);
    }

    public function updateForLead(Request $request, Lead $lead): JsonResponse
    {
        $this->authorizeLead($request, $lead);

        $data = $this->validated($request);
        $action = $this->upsertPending($lead, $data);

        $this->timeline->record(
            $lead,
            'next_action_set',
            "Next action set: {$action->action_description}",
            $request->user(),
            ['next_action_id' => $action->id, 'responsible_role' => $action->responsible_role],
        );

        return response()->json(['next_action' => $action->load('responsibleUser:id,name,role')]);
    }

    public function showForJob(Request $request, Job $job): JsonResponse
    {
        $this->authorizeJob($request, $job);

        return response()->json([
            'next_action' => $job->pendingNextAction()->with('responsibleUser:id,name,role')->first(),
        ]);
    }

    public function updateForJob(Request $request, Job $job): JsonResponse
    {
        $this->authorizeJob($request, $job);

        $data = $this->validated($request);
        $action = $this->upsertPending($job, $data);

        $this->timeline->record(
            $job,
            'next_action_set',
            "Next action set: {$action->action_description}",
            $request->user(),
            ['next_action_id' => $action->id, 'responsible_role' => $action->responsible_role],
        );

        return response()->json(['next_action' => $action->load('responsibleUser:id,name,role')]);
    }

    protected function upsertPending(Lead|Job $subject, array $data): NextAction
    {
        $existing = $subject->pendingNextAction()->first();

        if ($existing) {
            $existing->update([
                ...$data,
                'last_action_at' => now(),
            ]);

            return $existing->fresh();
        }

        return $subject->nextActions()->create([
            ...$data,
            'status' => 'pending',
            'last_action_at' => now(),
        ]);
    }

    protected function validated(Request $request): array
    {
        return $request->validate([
            'action_description' => 'required|string|max:500',
            'responsible_role' => 'required|in:owner,ai,pm,contractor,customer',
            'responsible_user_id' => 'nullable|exists:users,id',
            'due_at' => 'nullable|date',
            'escalation_rule' => 'nullable|string|max:255',
        ]);
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
        abort(403, 'Unauthorized');
    }
}
