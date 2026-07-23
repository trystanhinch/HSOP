<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Job;
use App\Models\Lead;
use App\Models\PricingOverrideLog;
use App\Services\Learning\EstimateOutcomeRecorder;
use App\Services\Learning\JobEstimateSnapshotService;
use App\Services\Pricing\PricingRangeEstimator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only Learning Centre foundation endpoints + estimate version capture.
 * No AI / recommendation logic.
 */
class LearningSnapshotController extends Controller
{
    public function __construct(
        private JobEstimateSnapshotService $snapshots,
        private EstimateOutcomeRecorder $outcomes,
        private PricingRangeEstimator $estimator,
    ) {}

    public function forJob(Job $job): JsonResponse
    {
        return response()->json([
            'snapshot' => $this->snapshots->forJob($job),
        ]);
    }

    public function forLead(Lead $lead): JsonResponse
    {
        return response()->json([
            'snapshot' => $this->snapshots->forLead($lead),
        ]);
    }

    /**
     * PM/Admin manually adjusts the customer-facing ballpark range (new estimate_outcomes version).
     */
    public function overrideLeadEstimate(Request $request, Lead $lead): JsonResponse
    {
        $data = $request->validate([
            'price_estimate_low' => 'required|numeric|min:0',
            'price_estimate_high' => 'required|numeric|gte:price_estimate_low',
            'reason' => 'nullable|string|max:500',
        ]);

        $before = [
            'price_estimate_low' => $lead->price_estimate_low,
            'price_estimate_high' => $lead->price_estimate_high,
            'price_estimate_snapshot' => $lead->price_estimate_snapshot,
        ];

        $snapshot = is_array($lead->price_estimate_snapshot) ? $lead->price_estimate_snapshot : [];
        $snapshot['low'] = (float) $data['price_estimate_low'];
        $snapshot['high'] = (float) $data['price_estimate_high'];
        $snapshot['available'] = true;
        $snapshot['manual_override'] = true;
        $snapshot['manual_override_reason'] = $data['reason'] ?? null;
        $snapshot['manual_override_at'] = now()->toIso8601String();
        $snapshot['manual_override_by'] = auth()->id();
        $snapshot['confidence'] = $snapshot['confidence'] ?? 'manual';
        $snapshot['service_category'] = $lead->service_category;
        $snapshot['inputs_used'] = array_merge(
            is_array($snapshot['inputs_used'] ?? null) ? $snapshot['inputs_used'] : [],
            ['service_category' => $lead->service_category]
        );

        $outcome = $this->outcomes->record($lead->fresh(), $snapshot, [
            'source_kind' => 'manual_override',
            'actor_id' => auth()->id(),
            'reason' => $data['reason'] ?? null,
        ]);

        PricingOverrideLog::create([
            'actor_id' => auth()->id(),
            'subject_type' => 'lead_estimate',
            'subject_id' => $lead->id,
            'brand_id' => $lead->brand_id,
            'lead_id' => $lead->id,
            'job_id' => Job::query()->where('lead_id', $lead->id)->value('id'),
            'estimate_outcome_id' => $outcome->id,
            'override_kind' => 'estimate_manual_adjust',
            'before_json' => $before,
            'after_json' => [
                'price_estimate_low' => $outcome->price_low,
                'price_estimate_high' => $outcome->price_high,
                'estimate_outcome_id' => $outcome->id,
                'version' => $outcome->version,
                'estimate_group_id' => $outcome->estimate_group_id,
            ],
            'reason' => $data['reason'] ?? null,
        ]);

        return response()->json([
            'lead' => $lead->fresh(),
            'estimate_outcome' => $outcome,
            'message' => 'Price estimate override recorded as a new version.',
        ]);
    }

    /**
     * Re-run the deterministic estimator with current lead fields — appends a new version.
     */
    public function recalculateLeadEstimate(Request $request, Lead $lead): JsonResponse
    {
        $data = $request->validate([
            'reason' => 'nullable|string|max:500',
            'size_sqft' => 'nullable|numeric|min:0',
            'complexity' => 'nullable|string|max:40',
            'urgency' => 'nullable|in:normal,high',
        ]);

        $brand = $lead->brand_id
            ? Brand::find($lead->brand_id)
            : Brand::query()->where('domain', config('public.local_default_brand_domain'))->first();

        if (! $brand) {
            return response()->json(['message' => 'Brand not found for estimate recalculation.'], 422);
        }

        if (! $lead->service_category) {
            return response()->json(['message' => 'Lead service_category is required before recalculating.'], 422);
        }

        $collected = $lead->parse_metadata['collected_fields'] ?? [];
        $estimate = $this->estimator->estimate($brand, [
            'service_category' => $lead->service_category,
            'size_sqft' => $data['size_sqft'] ?? ($collected['size_sqft'] ?? null),
            'complexity' => $data['complexity'] ?? ($collected['complexity'] ?? null),
            'urgency' => $data['urgency'] ?? ($collected['urgency'] ?? null),
            'project_description' => $lead->project_description,
            'address' => $lead->address,
        ]);

        $outcome = $this->outcomes->record($lead->fresh(), $estimate, [
            'source_kind' => 'recalculate',
            'actor_id' => auth()->id(),
            'reason' => $data['reason'] ?? 'PM recalculate',
            'ai_provider' => config('ai.conversational_provider'),
            'ai_model' => config('ai.openai.model'),
        ]);

        return response()->json([
            'lead' => $lead->fresh(),
            'estimate_outcome' => $outcome,
            'message' => 'Price estimate recalculated and stored as a new version.',
        ]);
    }
}
