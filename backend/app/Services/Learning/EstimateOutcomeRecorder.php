<?php

namespace App\Services\Learning;

use App\Models\EstimateOutcome;
use App\Models\Job;
use App\Models\Lead;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Appends versioned estimate_outcomes rows. Never overwrites prior versions.
 * Capture-only — no AI learning / recommendations.
 */
class EstimateOutcomeRecorder
{
    public const ENGINE = 'pricing_range_v1';

    /**
     * @param  array<string, mixed>  $estimate  PricingRangeEstimator payload (or manual override shape)
     * @param  array{
     *   source_kind?: string,
     *   actor_id?: int|null,
     *   reason?: string|null,
     *   ai_provider?: string|null,
     *   ai_model?: string|null,
     *   ai_model_version?: string|null,
     *   job_id?: int|null,
     * }  $meta
     */
    public function record(Lead $lead, array $estimate, array $meta = []): EstimateOutcome
    {
        $serviceCategory = $this->resolveServiceCategory($lead, $estimate);
        if ($serviceCategory === '') {
            $serviceCategory = 'unknown';
        }

        $aiMeta = $this->resolveAiMeta($lead, $estimate, $meta);

        return DB::transaction(function () use ($lead, $estimate, $meta, $serviceCategory, $aiMeta) {
            $previous = EstimateOutcome::query()
                ->where('lead_id', $lead->id)
                ->where('is_current', true)
                ->lockForUpdate()
                ->first();

            if (! $previous) {
                $previous = EstimateOutcome::query()
                    ->where('lead_id', $lead->id)
                    ->orderByDesc('version')
                    ->lockForUpdate()
                    ->first();
            }

            $groupId = $previous?->estimate_group_id ?? (string) Str::uuid();
            $version = $previous ? ((int) $previous->version + 1) : 1;

            if ($previous) {
                EstimateOutcome::query()
                    ->where('lead_id', $lead->id)
                    ->where('is_current', true)
                    ->update(['is_current' => false]);
            }

            $snapshot = $estimate;
            $snapshot['estimator_engine'] = $snapshot['estimator_engine'] ?? self::ENGINE;
            $snapshot['ai_provider'] = $aiMeta['ai_provider'];
            $snapshot['ai_model'] = $aiMeta['ai_model'];
            $snapshot['ai_model_version'] = $aiMeta['ai_model_version'];
            $snapshot['service_category'] = $serviceCategory;
            $snapshot['estimated_at'] = now()->toIso8601String();
            // Never populate embeddings in this phase
            unset($snapshot['embedding_vector']);

            $jobId = $meta['job_id'] ?? Job::query()->where('lead_id', $lead->id)->value('id');

            $row = EstimateOutcome::create([
                'estimate_group_id' => $groupId,
                'lead_id' => $lead->id,
                'job_id' => $jobId,
                'brand_id' => $lead->brand_id,
                'version' => $version,
                'source_kind' => $meta['source_kind'] ?? 'estimator',
                'service_category' => $serviceCategory,
                'price_low' => $estimate['low'] ?? null,
                'price_high' => $estimate['high'] ?? null,
                'currency' => $estimate['currency'] ?? 'CAD',
                'confidence' => $estimate['confidence'] ?? null,
                'available' => (bool) ($estimate['available'] ?? false),
                'widened' => (bool) ($estimate['widened'] ?? false),
                'is_placeholder' => (bool) ($estimate['is_placeholder'] ?? false),
                'is_current' => true,
                'pricing_rule_id' => $estimate['rule_id'] ?? null,
                'inputs_used' => $estimate['inputs_used'] ?? null,
                'calculation' => $estimate['calculation'] ?? null,
                'materials_assumptions' => $estimate['materials_assumptions'] ?? null,
                'labour_assumptions' => $estimate['labour_assumptions'] ?? null,
                'reasoning_snapshot' => $snapshot,
                'ai_provider' => $aiMeta['ai_provider'],
                'ai_model' => $aiMeta['ai_model'],
                'ai_model_version' => $aiMeta['ai_model_version'],
                'estimator_engine' => self::ENGINE,
                'estimated_at' => now(),
                'actor_id' => $meta['actor_id'] ?? null,
                'supersedes_id' => $previous?->id,
                'reason' => $meta['reason'] ?? null,
                'embedding_vector' => null,
                // Intentionally null: Trystan held off on weather API; column reserved for future env data.
                'environmental_context' => null,
            ]);

            // Lead columns remain the "current pointer" for existing UI — history lives in estimate_outcomes
            $lead->update([
                'price_estimate_low' => $row->price_low,
                'price_estimate_high' => $row->price_high,
                'price_estimate_snapshot' => $snapshot,
            ]);

            $parseMeta = $lead->parse_metadata ?? [];
            $parseMeta['price_estimate'] = $snapshot;
            $parseMeta['current_estimate_outcome_id'] = $row->id;
            $parseMeta['estimate_group_id'] = $groupId;
            $lead->update(['parse_metadata' => $parseMeta]);

            return $row;
        });
    }

    /**
     * @param  array<string, mixed>  $estimate
     * @param  array<string, mixed>  $meta
     * @return array{ai_provider: ?string, ai_model: ?string, ai_model_version: ?string}
     */
    private function resolveAiMeta(Lead $lead, array $estimate, array $meta): array
    {
        $usage = $lead->parse_metadata['ai_usage'] ?? null;
        $provider = $meta['ai_provider']
            ?? $estimate['ai_provider']
            ?? (is_array($usage) ? ($usage['provider'] ?? null) : null)
            ?? config('ai.conversational_provider')
            ?? config('ai.provider');

        $model = $meta['ai_model']
            ?? $estimate['ai_model']
            ?? (is_array($usage) ? ($usage['model'] ?? null) : null)
            ?? config('ai.openai.model');

        $version = $meta['ai_model_version']
            ?? $estimate['ai_model_version']
            ?? (is_array($usage) ? ($usage['model_version'] ?? null) : null);

        // Manual overrides are human-authored — do not attribute to AI
        if (($meta['source_kind'] ?? null) === 'manual_override') {
            return [
                'ai_provider' => null,
                'ai_model' => null,
                'ai_model_version' => null,
            ];
        }

        return [
            'ai_provider' => $provider ? (string) $provider : null,
            'ai_model' => $model ? (string) $model : null,
            'ai_model_version' => $version ? (string) $version : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $estimate
     */
    private function resolveServiceCategory(Lead $lead, array $estimate): string
    {
        $fromEstimate = $estimate['inputs_used']['service_category']
            ?? $estimate['service_category']
            ?? null;

        return trim((string) ($fromEstimate ?: $lead->service_category ?: ''));
    }
}
