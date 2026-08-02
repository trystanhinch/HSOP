<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\LearningGateway\LearningAiWriteTools;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Milestone 6B Phase 4 — Learning AI gateway write tools.
 * Evidence + recommend only. Never approve / never mutate source-of-truth fields.
 */
class LearningGatewayController extends Controller
{
    public function ping(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'tool' => 'ping',
            'tool_version' => '1.0.0',
            'ok' => true,
            'actor_role' => $user?->role,
            'actor_user_id' => $user?->id,
            'message' => 'Learning AI gateway auth OK.',
        ]);
    }

    public function normalizedRecord(Request $request, LearningAiWriteTools $tools): JsonResponse
    {
        $payload = $request->all();

        try {
            $result = $tools->createNormalizedRecord($payload, $request->user());
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'tool' => 'normalized-record'], 422);
        }

        $record = $result['record'];

        return response()->json([
            'tool' => 'normalized-record',
            'tool_version' => '1.0.0',
            'record' => [
                'id' => $record->id,
                'subject_type' => $record->subject_type,
                'subject_id' => $record->subject_id,
                'job_id' => $record->job_id,
                'estimate_outcome_id' => $record->estimate_outcome_id,
                'lead_id' => $record->lead_id,
                'learning_eligibility_status' => $record->learning_eligibility_status,
                'extracted_fields' => $record->extracted_fields,
                'provenance' => $record->provenance,
                'confidence' => $record->confidence,
                'warnings' => $record->warnings,
                'missing_data_flags' => $record->missing_data_flags,
                'notes' => $record->notes,
                'created_by' => $record->created_by,
                'created_at' => optional($record->created_at)?->toIso8601String(),
            ],
            'stripped_fields' => $result['stripped_fields'],
            'note' => 'Draft only — status is pending_review or provisional. Verified/Excluded require human finalize.',
        ], 201);
    }

    public function evidence(Request $request, LearningAiWriteTools $tools): JsonResponse
    {
        try {
            $result = $tools->attachEvidence($request->all(), $request->user());
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'tool' => 'evidence'], 422);
        }

        $entry = $result['entry'];

        return response()->json([
            'tool' => 'evidence',
            'tool_version' => '1.0.0',
            'entry' => [
                'id' => $entry->id,
                'learning_normalized_record_id' => $entry->learning_normalized_record_id,
                'confidence' => $entry->confidence,
                'source_references' => $entry->source_references,
                'warnings' => $entry->warnings,
                'missing_data_flags' => $entry->missing_data_flags,
                'notes' => $entry->notes,
                'created_by' => $entry->created_by,
                'created_at' => optional($entry->created_at)?->toIso8601String(),
            ],
            'stripped_fields' => $result['stripped_fields'],
            'note' => 'Append-only evidence entry; prior entries are never overwritten.',
        ], 201);
    }

    public function recommendation(Request $request, LearningAiWriteTools $tools): JsonResponse
    {
        try {
            $result = $tools->submitRecommendation($request->all(), $request->user());
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'tool' => 'recommendation'], 422);
        }

        return response()->json(array_merge([
            'tool' => 'recommendation',
            'tool_version' => '1.0.0',
            'note' => 'Uses the same LearningEligibilityService::recommend path as PMs. Does not finalize.',
        ], $result));
    }
}
