<?php

namespace App\Console\Commands;

use App\Models\AiConversationLog;
use App\Models\AiEvaluationFinding;
use App\Models\AiEvaluationRun;
use App\Models\User;
use App\Services\ReviewGateway\ExternalReviewAiPrincipal;
use App\Services\ReviewGateway\PlaceholderEvaluationScorer;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Smoke-test the evaluation harness against existing ai_conversation_logs.
 * Does NOT call OpenAI — uses PlaceholderEvaluationScorer only.
 */
class SmokeEvaluationHarnessCommand extends Command
{
    protected $signature = 'review-ai:smoke-evaluation
                            {--limit=5 : Max conversation log rows to score}
                            {--dry-run : Score without persisting}';

    protected $description = 'Create a smoke evaluation run + findings from local ai_conversation_logs (no live LLM)';

    public function handle(PlaceholderEvaluationScorer $scorer, ExternalReviewAiPrincipal $principal): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $logs = AiConversationLog::query()->orderByDesc('id')->limit($limit)->get();

        if ($logs->isEmpty()) {
            $this->warn('No ai_conversation_logs found — nothing to score.');

            return self::FAILURE;
        }

        $cfg = config('review_gateway.evaluation', []);
        $user = User::query()->where('email', $principal->email())->first();

        $meta = [
            'provider' => $cfg['default_provider'] ?? 'openai',
            'model' => $cfg['default_model'] ?? 'placeholder-scorer',
            'model_version' => $cfg['default_model_version'] ?? 'smoke-1',
            'prompt_version' => $cfg['prompt_version'] ?? 'smoke-placeholder-1.0.0',
            'evaluation_version' => $cfg['evaluation_version'] ?? '1.0.0',
            'benchmark_set_version' => $cfg['benchmark_set_version'] ?? 'smoke-local-v1',
            'run_type' => 'smoke',
            'status' => 'completed',
            'total_cost' => 0,
            'started_at' => now(),
            'completed_at' => now(),
            'trace_id' => (string) Str::uuid(),
            'initiated_by_type' => $user ? 'user' : 'user',
            'initiated_by_id' => $user?->id,
            'actor_user_id' => $user?->id,
            'personal_access_token_id' => null,
            'created_at' => now(),
        ];

        $this->info('Scoring '.$logs->count().' conversation log(s) with placeholder scorer…');

        if ($this->option('dry-run')) {
            foreach ($logs as $log) {
                $scores = $scorer->scoreConversation($log);
                $this->line("  log#{$log->id} → ".count($scores).' findings');
            }
            $this->comment('Dry run — nothing persisted.');

            return self::SUCCESS;
        }

        $run = AiEvaluationRun::create($meta);
        $findingCount = 0;

        foreach ($logs as $log) {
            foreach ($scorer->scoreConversation($log) as $row) {
                AiEvaluationFinding::create([
                    'evaluation_run_id' => $run->id,
                    'subject_type' => 'ai_conversation_log',
                    'subject_id' => $log->id,
                    'dimension' => $row['dimension'],
                    'score' => $row['score'],
                    'max_score' => $row['max_score'],
                    'critique' => $row['critique'],
                    'statement_kind' => $row['statement_kind'],
                    'evidence_reference' => 'ai_conversation_log:'.$log->id,
                    'created_at' => now(),
                ]);
                $findingCount++;
            }
        }

        $this->info("Run #{$run->id} completed — {$findingCount} findings (cost={$run->total_cost}, provider={$run->provider}).");

        return self::SUCCESS;
    }
}
