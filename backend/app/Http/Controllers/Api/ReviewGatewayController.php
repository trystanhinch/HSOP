<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ReviewGateway\AiConversationLogTool;
use App\Services\ReviewGateway\EvaluationFindingTool;
use App\Services\ReviewGateway\EvaluationRunTool;
use App\Services\ReviewGateway\LeadJourneyTool;
use App\Services\ReviewGateway\ReviewSearchTool;
use App\Services\ReviewGateway\SourceFileTool;
use App\Services\ReviewGateway\SourceSearchTool;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Milestone 6A — External Review AI tools.
 * Data + source remain GET-only; Phase 5 adds narrow POST evaluation write tools
 * gated by review:evidence-write.
 */
class ReviewGatewayController extends Controller
{
    public function leadJourney(int $leadId, LeadJourneyTool $tool): JsonResponse
    {
        return response()->json($tool->handle($leadId));
    }

    public function search(Request $request, ReviewSearchTool $tool): JsonResponse
    {
        return response()->json($tool->handle($request));
    }

    public function aiConversationLog(int $conversationId, AiConversationLogTool $tool): JsonResponse
    {
        return response()->json($tool->handle($conversationId));
    }

    public function sourceFile(Request $request, SourceFileTool $tool): JsonResponse
    {
        $path = (string) $request->query('path', '');
        $result = $tool->handle($path);
        if (! $result['ok']) {
            $request->attributes->set('review_gateway_denial_reason', $result['denial_reason'] ?? 'path_denied');

            return response()->json($result['payload'], $result['status']);
        }

        return response()->json($result['payload']);
    }

    public function sourceSearch(Request $request, SourceSearchTool $tool): JsonResponse
    {
        $query = (string) $request->query('query', '');
        $prefix = $request->query('path_prefix');
        $prefix = is_string($prefix) ? $prefix : null;
        $result = $tool->handle($query, $prefix);
        if (! $result['ok']) {
            $request->attributes->set('review_gateway_denial_reason', $result['denial_reason'] ?? 'search_denied');

            return response()->json($result['payload'], $result['status']);
        }

        return response()->json($result['payload']);
    }

    /** Phase 5 — first write tool; requires review:evidence-write. */
    public function evaluationRun(Request $request, EvaluationRunTool $tool): JsonResponse
    {
        return response()->json($tool->handle($request), 201);
    }

    /** Phase 5 — append finding to an owned evaluation run. */
    public function evaluationFinding(Request $request, EvaluationFindingTool $tool): JsonResponse
    {
        return response()->json($tool->handle($request), 201);
    }
}
