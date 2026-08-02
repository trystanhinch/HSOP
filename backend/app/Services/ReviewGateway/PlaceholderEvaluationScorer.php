<?php

namespace App\Services\ReviewGateway;

use App\Models\AiConversationLog;

/**
 * Deterministic placeholder scorer for smoke tests — NOT a live OpenAI call.
 * Scores a small sample of conversation logs on a subset of Core Evaluation Dimensions
 * so the write path can be proven without staging or API spend.
 */
class PlaceholderEvaluationScorer
{
    /** Dimensions used in the smoke harness (subset of the full Core set). */
    public const SMOKE_DIMENSIONS = [
        'scope_completeness',
        'factual_grounding',
        'tool_correctness',
    ];

    /**
     * @return list<array{
     *   dimension: string,
     *   score: float,
     *   max_score: float,
     *   critique: string,
     *   statement_kind: string
     * }>
     */
    public function scoreConversation(AiConversationLog $log): array
    {
        $content = (string) ($log->content ?? '');
        $len = mb_strlen($content);
        $hasTools = ! empty($log->tool_calls);
        $hasResults = ! empty($log->tool_results);

        $scope = $len >= 40 ? 4.0 : ($len >= 10 ? 3.0 : 2.0);
        $grounding = ($log->ai_provider && $log->ai_model) ? 4.0 : 2.5;
        if (str_contains(mb_strtolower($content), 'i guess') || str_contains(mb_strtolower($content), 'maybe')) {
            $grounding = max(1.0, $grounding - 1.0);
        }
        $tools = 3.0;
        if ($log->role === 'assistant' && $hasTools) {
            $tools = $hasResults ? 4.5 : 3.5;
        } elseif ($log->role === 'assistant' && ! $hasTools) {
            $tools = 3.0;
        } else {
            $tools = 4.0; // user/system turns: no tool expectation
        }

        return [
            [
                'dimension' => 'scope_completeness',
                'score' => $scope,
                'max_score' => 5.0,
                'critique' => "Placeholder scope score from content length={$len}.",
                'statement_kind' => 'inference',
            ],
            [
                'dimension' => 'factual_grounding',
                'score' => $grounding,
                'max_score' => 5.0,
                'critique' => 'Placeholder grounding from provider/model presence and hedging language.',
                'statement_kind' => 'observed_fact',
            ],
            [
                'dimension' => 'tool_correctness',
                'score' => $tools,
                'max_score' => 5.0,
                'critique' => 'Placeholder tool score from tool_calls/tool_results presence.',
                'statement_kind' => 'recommendation',
            ],
        ];
    }
}
