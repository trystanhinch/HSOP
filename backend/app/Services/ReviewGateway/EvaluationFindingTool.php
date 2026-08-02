<?php

namespace App\Services\ReviewGateway;

use App\Models\AiActionLog;
use App\Models\AiConversationLog;
use App\Models\AiEvaluationFinding;
use App\Models\AiEvaluationRun;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * POST evaluation-finding — writes one append-only finding to a run owned by the actor.
 */
class EvaluationFindingTool
{
    public const TOOL = 'evaluation_finding';

    public function __construct(private SensitiveDataGuard $guard) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(Request $request): array
    {
        $cfg = config('review_gateway.evaluation', []);
        $dimensions = $cfg['dimensions'] ?? [];
        $kinds = $cfg['statement_kinds'] ?? ['observed_fact', 'inference', 'recommendation'];
        $subjectTypes = $cfg['subject_types'] ?? ['ai_conversation_log', 'ai_action_log'];

        $data = $request->validate([
            'evaluation_run_id' => ['required', 'integer'],
            'subject_type' => ['required', 'string', Rule::in($subjectTypes)],
            'subject_id' => ['required', 'integer', 'min:1'],
            'dimension' => ['required', 'string', Rule::in($dimensions)],
            'score' => ['required', 'numeric'],
            'max_score' => ['sometimes', 'numeric', 'min:0.01'],
            'critique' => ['nullable', 'string', 'max:10000'],
            'statement_kind' => ['required', 'string', Rule::in($kinds)],
            'evidence_reference' => ['nullable', 'string', 'max:512'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $run = AiEvaluationRun::query()->find($data['evaluation_run_id']);
        if (! $run) {
            abort(404, 'Evaluation run not found.');
        }

        // Same actor only — never write findings onto another principal's run.
        if ((int) $run->actor_user_id !== (int) $user->id) {
            abort(403, 'You may only write findings to evaluation runs you initiated.');
        }

        $this->assertSubjectExists($data['subject_type'], (int) $data['subject_id']);

        $maxScore = isset($data['max_score']) ? (float) $data['max_score'] : 5.0;
        $score = (float) $data['score'];
        if ($score < 0 || $score > $maxScore) {
            throw ValidationException::withMessages([
                'score' => ["Score must be between 0 and {$maxScore}."],
            ]);
        }

        $evidence = $data['evidence_reference']
            ?? ($data['subject_type'].':'.$data['subject_id']);

        $finding = AiEvaluationFinding::create([
            'evaluation_run_id' => $run->id,
            'subject_type' => $data['subject_type'],
            'subject_id' => $data['subject_id'],
            'dimension' => $data['dimension'],
            'score' => $score,
            'max_score' => $maxScore,
            'critique' => $data['critique'] ?? null,
            'statement_kind' => $data['statement_kind'],
            'evidence_reference' => $evidence,
            'created_at' => now(),
        ]);

        $payload = [
            'tool' => self::TOOL,
            'tool_version' => config('review_gateway.tool_versions.evaluation_finding', '1.0.0'),
            'finding' => $this->serializeFinding($finding),
        ];

        return $this->guard->scrub($payload);
    }

    private function assertSubjectExists(string $type, int $id): void
    {
        $exists = match ($type) {
            'ai_conversation_log' => AiConversationLog::query()->whereKey($id)->exists(),
            'ai_action_log' => AiActionLog::query()->whereKey($id)->exists(),
            default => false,
        };

        if (! $exists) {
            throw ValidationException::withMessages([
                'subject_id' => ["Subject {$type}#{$id} was not found."],
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeFinding(AiEvaluationFinding $finding): array
    {
        return [
            'id' => $finding->id,
            'evaluation_run_id' => $finding->evaluation_run_id,
            'subject_type' => $finding->subject_type,
            'subject_id' => $finding->subject_id,
            'dimension' => $finding->dimension,
            'score' => (string) $finding->score,
            'max_score' => (string) $finding->max_score,
            'critique' => $finding->critique,
            'statement_kind' => $finding->statement_kind,
            'evidence_reference' => $finding->evidence_reference,
            'created_at' => optional($finding->created_at)?->toIso8601String(),
        ];
    }
}
