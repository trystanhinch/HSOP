<?php

namespace App\Services\ReviewGateway;

use App\Models\AiEvaluationRun;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * POST evaluation-run — first review:evidence-write tool (Milestone 6A.3).
 * Creates an append-only ai_evaluation_runs row with provider-neutral metadata.
 */
class EvaluationRunTool
{
    public const TOOL = 'evaluation_run';

    public function __construct(private SensitiveDataGuard $guard) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(Request $request): array
    {
        $dimensionsCfg = config('review_gateway.evaluation', []);
        $runTypes = $dimensionsCfg['run_types'] ?? ['manual', 'scheduled', 'triggered-by-change', 'smoke'];
        $statuses = $dimensionsCfg['run_statuses'] ?? ['running', 'completed', 'failed'];

        $data = $request->validate([
            'provider' => ['required', 'string', 'max:64'],
            'model' => ['required', 'string', 'max:128'],
            'model_version' => ['nullable', 'string', 'max:128'],
            'prompt_version' => ['required', 'string', 'max:128'],
            'evaluation_version' => ['required', 'string', 'max:128'],
            'benchmark_set_version' => ['nullable', 'string', 'max:128'],
            'run_type' => ['required', 'string', Rule::in($runTypes)],
            'status' => ['sometimes', 'string', Rule::in($statuses)],
            'total_cost' => ['sometimes', 'numeric', 'min:0'],
            'started_at' => ['sometimes', 'date'],
            'completed_at' => ['nullable', 'date'],
            'trace_id' => ['sometimes', 'string', 'max:64'],
        ]);

        $status = $data['status'] ?? 'running';
        if ($status === 'completed' && empty($data['completed_at'])) {
            $data['completed_at'] = now()->toIso8601String();
        }
        if ($status === 'running' && ! empty($data['completed_at'])) {
            throw ValidationException::withMessages([
                'completed_at' => ['completed_at must be null while status is running.'],
            ]);
        }

        /** @var User $user */
        $user = $request->user();
        $token = $user->currentAccessToken();
        $tokenId = $token instanceof PersonalAccessToken ? $token->id : null;

        $traceId = (string) ($data['trace_id']
            ?? $request->attributes->get('review_gateway_trace_id')
            ?? $request->headers->get('X-Correlation-Id')
            ?? Str::uuid());

        $run = AiEvaluationRun::create([
            'provider' => $data['provider'],
            'model' => $data['model'],
            'model_version' => $data['model_version'] ?? null,
            'prompt_version' => $data['prompt_version'],
            'evaluation_version' => $data['evaluation_version'],
            'benchmark_set_version' => $data['benchmark_set_version'] ?? null,
            'run_type' => $data['run_type'],
            'initiated_by_type' => 'personal_access_token',
            'initiated_by_id' => $tokenId,
            'actor_user_id' => $user->id,
            'personal_access_token_id' => $tokenId,
            'started_at' => isset($data['started_at']) ? $data['started_at'] : now(),
            'completed_at' => $data['completed_at'] ?? null,
            'total_cost' => $data['total_cost'] ?? 0,
            'status' => $status,
            'trace_id' => $traceId,
            'created_at' => now(),
        ]);

        $payload = [
            'tool' => self::TOOL,
            'tool_version' => config('review_gateway.tool_versions.evaluation_run', '1.0.0'),
            'run' => $this->serializeRun($run),
        ];

        return $this->guard->scrub($payload);
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeRun(AiEvaluationRun $run): array
    {
        return [
            'id' => $run->id,
            'provider' => $run->provider,
            'model' => $run->model,
            'model_version' => $run->model_version,
            'prompt_version' => $run->prompt_version,
            'evaluation_version' => $run->evaluation_version,
            'benchmark_set_version' => $run->benchmark_set_version,
            'run_type' => $run->run_type,
            'initiated_by_type' => $run->initiated_by_type,
            'initiated_by_id' => $run->initiated_by_id,
            'actor_user_id' => $run->actor_user_id,
            'personal_access_token_id' => $run->personal_access_token_id,
            'started_at' => optional($run->started_at)?->toIso8601String(),
            'completed_at' => optional($run->completed_at)?->toIso8601String(),
            'total_cost' => (string) $run->total_cost,
            'status' => $run->status,
            'trace_id' => $run->trace_id,
            'created_at' => optional($run->created_at)?->toIso8601String(),
        ];
    }
}
