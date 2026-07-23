<?php

namespace App\Services\Ai;

/**
 * Shared OpenAI token → estimated USD helpers.
 */
trait TracksOpenAiUsage
{
    /**
     * @param  array<string, mixed>  $usage
     * @return array<string, mixed>
     */
    protected function buildUsagePayload(array $usage, string $model): array
    {
        $prompt = (int) ($usage['prompt_tokens'] ?? 0);
        $completion = (int) ($usage['completion_tokens'] ?? 0);
        $total = (int) ($usage['total_tokens'] ?? ($prompt + $completion));

        $inputRate = (float) config('ai.openai.cost_per_1m_input_tokens', 0.15);
        $outputRate = (float) config('ai.openai.cost_per_1m_output_tokens', 0.60);
        $estimated = ($prompt / 1_000_000 * $inputRate) + ($completion / 1_000_000 * $outputRate);

        return [
            'model' => $model,
            'prompt_tokens' => $prompt,
            'completion_tokens' => $completion,
            'total_tokens' => $total,
            'estimated_cost_usd' => round($estimated, 6),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $a
     * @param  array<string, mixed>|null  $b
     * @return array<string, mixed>|null
     */
    protected function mergeUsage(?array $a, ?array $b): ?array
    {
        if ($a === null) {
            return $b;
        }
        if ($b === null) {
            return $a;
        }

        $prompt = (int) ($a['prompt_tokens'] ?? 0) + (int) ($b['prompt_tokens'] ?? 0);
        $completion = (int) ($a['completion_tokens'] ?? 0) + (int) ($b['completion_tokens'] ?? 0);
        $model = (string) ($b['model'] ?? $a['model'] ?? config('ai.openai.model', 'gpt-4o-mini'));

        return $this->buildUsagePayload([
            'prompt_tokens' => $prompt,
            'completion_tokens' => $completion,
            'total_tokens' => $prompt + $completion,
        ], $model);
    }
}
