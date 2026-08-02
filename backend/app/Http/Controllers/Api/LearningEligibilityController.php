<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EstimateOutcome;
use App\Models\Job;
use App\Services\Learning\LearningEligibilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use RuntimeException;

/**
 * Milestone 6B Phase 3 — recommend vs finalize (old direct PATCH removed).
 */
class LearningEligibilityController extends Controller
{
    public function __construct(private LearningEligibilityService $eligibility) {}

    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status');
        $allowed = $this->eligibility->allowedStatuses();

        $query = EstimateOutcome::query()
            ->with([
                'lead:id,contact_name,service_category,brand_id',
                'job:id,status,service_category,learning_eligibility_status,learning_recommended_status,learning_recommended_by,learning_recommended_at,learning_approved_at',
            ])
            ->orderByDesc('id');

        if (is_string($status) && $status !== '') {
            if (! in_array($status, $allowed, true)) {
                return response()->json([
                    'message' => 'Invalid status filter.',
                    'allowed' => $allowed,
                ], 422);
            }
            $query->where('learning_eligibility_status', $status);
        }

        if ($request->query('recommendation_state') === 'pending_approval') {
            $query->whereNotNull('learning_recommended_status')
                ->where(function ($q) {
                    $q->whereNull('learning_approved_at')
                        ->orWhereColumn('learning_recommended_at', '>', 'learning_approved_at');
                });
        }

        if ($request->filled('brand_id')) {
            $query->where('brand_id', (int) $request->brand_id);
        }

        $perPage = min(100, max(1, (int) $request->get('per_page', 25)));
        $page = $query->paginate($perPage);

        $data = collect($page->items())
            ->map(fn (EstimateOutcome $row) => $this->eligibility->serializeOutcome($row))
            ->values();

        return response()->json([
            'data' => $data,
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'total' => $page->total(),
            'per_page' => $page->perPage(),
            'viewer' => [
                'can_finalize' => $this->eligibility->actorCanFinalize($request->user()),
            ],
        ]);
    }

    public function recommend(Request $request, int $estimateOutcomeId): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'string', Rule::in($this->eligibility->allowedStatuses())],
            'reason' => ['required', 'string', 'min:1', 'max:5000'],
            'missing_actuals' => ['nullable'],
        ]);

        $outcome = EstimateOutcome::query()->find($estimateOutcomeId);
        if (! $outcome) {
            return response()->json(['message' => 'Estimate outcome not found.'], 404);
        }

        try {
            $result = $this->eligibility->recommendEstimateOutcome(
                $outcome,
                $data['status'],
                $data['reason'],
                $request->user(),
                $data['missing_actuals'] ?? null,
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        /** @var EstimateOutcome $fresh */
        $fresh = $result['outcome'];

        return response()->json([
            'estimate_outcome' => $this->eligibility->serializeOutcome($fresh),
            'flags' => $result['flags'],
            'recommendation_state' => $result['recommendation_state'],
        ]);
    }

    public function approve(Request $request, int $estimateOutcomeId): JsonResponse
    {
        if (! $this->eligibility->actorCanFinalize($request->user())) {
            return response()->json([
                'message' => 'Forbidden: finalizing eligibility requires Owner or can_finalize_learning_eligibility.',
            ], 403);
        }

        $data = $request->validate([
            'status' => ['required', 'string', Rule::in($this->eligibility->allowedStatuses())],
            'reason' => ['required', 'string', 'min:1', 'max:5000'],
        ]);

        $outcome = EstimateOutcome::query()->find($estimateOutcomeId);
        if (! $outcome) {
            return response()->json(['message' => 'Estimate outcome not found.'], 404);
        }

        try {
            $result = $this->eligibility->approveEstimateOutcome(
                $outcome,
                $data['status'],
                $data['reason'],
                $request->user(),
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }

        /** @var EstimateOutcome $fresh */
        $fresh = $result['outcome'];

        return response()->json([
            'estimate_outcome' => $this->eligibility->serializeOutcome($fresh),
            'flags' => $result['flags'],
            'recommendation_state' => $result['recommendation_state'],
            'override' => $result['override'],
        ]);
    }

    public function recommendJob(Request $request, Job $job): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'string', Rule::in($this->eligibility->allowedStatuses())],
            'reason' => ['required', 'string', 'min:1', 'max:5000'],
            'missing_actuals' => ['nullable'],
        ]);

        try {
            $result = $this->eligibility->recommendJob(
                $job,
                $data['status'],
                $data['reason'],
                $request->user(),
                $data['missing_actuals'] ?? null,
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        /** @var Job $fresh */
        $fresh = $result['job'];

        return response()->json([
            'job' => [
                'id' => $fresh->id,
                'learning_eligibility_status' => $fresh->learning_eligibility_status,
                'learning_recommended_status' => $fresh->learning_recommended_status,
                'learning_recommended_by' => $fresh->learning_recommended_by,
                'learning_recommended_at' => optional($fresh->learning_recommended_at)?->toIso8601String(),
                'learning_recommendation_reason' => $fresh->learning_recommendation_reason,
                'learning_recommendation_missing_actuals' => $fresh->learning_recommendation_missing_actuals,
                'learning_approved_by' => $fresh->learning_approved_by,
                'learning_approved_at' => optional($fresh->learning_approved_at)?->toIso8601String(),
                'recommendation_state' => $result['recommendation_state'],
            ],
            'flags' => $result['flags'],
        ]);
    }

    public function approveJob(Request $request, Job $job): JsonResponse
    {
        if (! $this->eligibility->actorCanFinalize($request->user())) {
            return response()->json([
                'message' => 'Forbidden: finalizing eligibility requires Owner or can_finalize_learning_eligibility.',
            ], 403);
        }

        $data = $request->validate([
            'status' => ['required', 'string', Rule::in($this->eligibility->allowedStatuses())],
            'reason' => ['required', 'string', 'min:1', 'max:5000'],
        ]);

        try {
            $result = $this->eligibility->approveJob(
                $job,
                $data['status'],
                $data['reason'],
                $request->user(),
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }

        /** @var Job $fresh */
        $fresh = $result['job'];

        return response()->json([
            'job' => [
                'id' => $fresh->id,
                'learning_eligibility_status' => $fresh->learning_eligibility_status,
                'learning_eligibility_reason' => $fresh->learning_eligibility_reason,
                'learning_recommended_status' => $fresh->learning_recommended_status,
                'learning_approved_by' => $fresh->learning_approved_by,
                'learning_approved_at' => optional($fresh->learning_approved_at)?->toIso8601String(),
                'recommendation_state' => $result['recommendation_state'],
            ],
            'flags' => $result['flags'],
            'override' => $result['override'],
        ]);
    }
}
