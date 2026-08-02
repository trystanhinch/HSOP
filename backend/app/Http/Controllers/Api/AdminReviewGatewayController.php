<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiEvaluationFinding;
use App\Models\AiEvaluationRun;
use App\Models\AuditLog;
use App\Models\ReviewGatewayAccessLog;
use App\Models\Setting;
use App\Models\User;
use App\Services\ReviewGateway\ExternalReviewAiPrincipal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Milestone 6A Phase 3/4 — Owner Review Center (human admin, not External Review AI).
 * Gated by role:owner only — never by review:* token abilities.
 * Active tokens = external_review_ai tokens only (Phase 4).
 */
class AdminReviewGatewayController extends Controller
{
    public function __construct(private ExternalReviewAiPrincipal $principal) {}

    /** @return list<string> */
    private function reviewAbilities(): array
    {
        return $this->principal->abilities();
    }

    private function killSwitchKey(): string
    {
        return (string) config('review_gateway.kill_switch_setting_key', 'review_gateway_kill_switch');
    }

    private function reviewTokensQuery()
    {
        return $this->principal->activeTokensQuery();
    }

    public function summary(): JsonResponse
    {
        $now = now();
        $windows = [
            '24h' => $now->copy()->subDay(),
            '7d' => $now->copy()->subDays(7),
            '30d' => $now->copy()->subDays(30),
        ];

        $calls = [];
        $denied = [];
        foreach ($windows as $label => $from) {
            $calls[$label] = ReviewGatewayAccessLog::query()
                ->where('created_at', '>=', $from)
                ->count();
            $denied[$label] = ReviewGatewayAccessLog::query()
                ->where('created_at', '>=', $from)
                ->where('outcome', 'denied')
                ->count();
        }

        $tokenQuery = $this->reviewTokensQuery();
        $activeTokens = (clone $tokenQuery)->count();
        $latestToken = (clone $tokenQuery)
            ->orderByDesc('last_used_at')
            ->orderByDesc('id')
            ->first(['id', 'name', 'last_used_at', 'created_at', 'expires_at']);

        $warningDays = max(1, (int) config('review_gateway.token_expiry_warning_days', 14));
        $horizon = $now->copy()->addDays($warningDays);
        $nearing = (clone $tokenQuery)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $horizon)
            ->where('expires_at', '>', $now)
            ->orderBy('expires_at')
            ->get(['id', 'name', 'expires_at'])
            ->map(fn (PersonalAccessToken $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'expires_at' => optional($t->expires_at)?->toIso8601String(),
                'days_left' => $t->expires_at ? (int) floor($now->diffInDays($t->expires_at, false)) : null,
            ])
            ->values()
            ->all();

        $legacyCount = $this->principal->legacyTokensQuery()->count();
        $killOn = Setting::getBool($this->killSwitchKey(), false);

        return response()->json([
            'identity' => [
                'role' => $this->principal->role(),
                'email' => $this->principal->email(),
                'label' => 'External Review AI (dedicated service identity)',
                'never_inherits' => 'ai_super_admin',
            ],
            'calls' => $calls,
            'denied' => $denied,
            'active_token_count' => $activeTokens,
            'tokens_nearing_expiration' => $nearing,
            'token_expiry_warning_days' => $warningDays,
            'legacy_token_count' => $legacyCount,
            'kill_switch' => $killOn,
            'kill_switch_setting_key' => $this->killSwitchKey(),
            'most_recently_used_token' => $latestToken ? [
                'id' => $latestToken->id,
                'name' => $latestToken->name,
                'last_used_at' => optional($latestToken->last_used_at)?->toIso8601String(),
                'created_at' => optional($latestToken->created_at)?->toIso8601String(),
                'expires_at' => optional($latestToken->expires_at)?->toIso8601String(),
            ] : null,
        ]);
    }

    public function accessLogs(Request $request): JsonResponse
    {
        $query = ReviewGatewayAccessLog::query()->orderByDesc('id');

        if ($request->filled('outcome')) {
            $query->where('outcome', $request->string('outcome')->toString());
        }
        if ($request->filled('tool')) {
            $query->where('tool', $request->string('tool')->toString());
        }
        if ($request->filled('token_name')) {
            $query->where('token_name', 'like', '%'.$request->string('token_name')->toString().'%');
        }
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->string('date_from')->toString());
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->string('date_to')->toString());
        }

        $perPage = min(100, max(1, (int) $request->get('per_page', 25)));

        return response()->json($query->paginate($perPage));
    }

    public function tokens(): JsonResponse
    {
        $rows = $this->reviewTokensQuery()
            ->orderByDesc('id')
            ->get(['id', 'name', 'abilities', 'tokenable_type', 'tokenable_id', 'last_used_at', 'created_at', 'expires_at']);

        $warningDays = max(1, (int) config('review_gateway.token_expiry_warning_days', 14));
        $now = now();
        $horizon = $now->copy()->addDays($warningDays);

        $data = $rows->map(function (PersonalAccessToken $t) use ($now, $horizon) {
            $abilities = $t->abilities;
            if (is_string($abilities)) {
                $abilities = json_decode($abilities, true) ?: [];
            }

            $nearing = $t->expires_at
                && $t->expires_at->lte($horizon)
                && $t->expires_at->gt($now);

            return [
                'id' => $t->id,
                'name' => $t->name,
                'actor_role' => $this->principal->role(),
                'abilities' => array_values(array_intersect(
                    is_array($abilities) ? $abilities : [],
                    $this->reviewAbilities()
                )) ?: (is_array($abilities) ? $abilities : []),
                'abilities_all' => is_array($abilities) ? $abilities : [],
                'tokenable_type' => $t->tokenable_type,
                'tokenable_id' => $t->tokenable_id,
                'last_used_at' => optional($t->last_used_at)?->toIso8601String(),
                'created_at' => optional($t->created_at)?->toIso8601String(),
                'expires_at' => optional($t->expires_at)?->toIso8601String(),
                'nearing_expiration' => (bool) $nearing,
            ];
        });

        return response()->json([
            'identity' => [
                'role' => $this->principal->role(),
                'email' => $this->principal->email(),
            ],
            'data' => $data,
        ]);
    }

    public function revokeToken(Request $request, int $id): JsonResponse
    {
        $token = $this->reviewTokensQuery()->where('id', $id)->first();
        if (! $token) {
            return response()->json(['message' => 'Review token not found.'], 404);
        }

        $snapshot = [
            'id' => $token->id,
            'name' => $token->name,
            'abilities' => $token->abilities,
            'tokenable_id' => $token->tokenable_id,
        ];

        $token->delete();

        /** @var User $actor */
        $actor = $request->user();
        AuditLog::create([
            'user_id' => $actor->id,
            'user_role' => $actor->role,
            'object_type' => 'personal_access_token',
            'object_id' => $id,
            'action_type' => 'review_gateway_token_revoked',
            'previous_value' => $snapshot,
            'new_value' => ['revoked' => true],
            'reason' => 'Owner revoked External Review AI token via Review Center',
            'created_at' => now(),
        ]);

        return response()->json([
            'message' => 'Token revoked.',
            'id' => $id,
        ]);
    }

    public function updateKillSwitch(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);

        // enabled=true means kill switch engaged (gateway blocked) — same semantics as ai_kill_switch.
        $key = $this->killSwitchKey();
        $before = Setting::getBool($key, false);
        $after = (bool) $data['enabled'];
        Setting::setBool($key, $after);

        if ($after) {
            app(\App\Listeners\DispatchAlertOnKillSwitchEngaged::class)->handle(
                'review',
                true,
                $request->user()?->id
            );
        }

        /** @var User $actor */
        $actor = $request->user();
        AuditLog::create([
            'user_id' => $actor->id,
            'user_role' => $actor->role,
            'object_type' => 'setting',
            'object_id' => 0,
            'action_type' => 'review_gateway_kill_switch_changed',
            'previous_value' => ['review_gateway_kill_switch' => $before],
            'new_value' => ['review_gateway_kill_switch' => $after],
            'reason' => $after
                ? 'Owner engaged review gateway kill switch via Review Center'
                : 'Owner cleared review gateway kill switch via Review Center',
            'created_at' => now(),
        ]);

        return response()->json([
            'kill_switch' => $after,
            'message' => $after
                ? 'Review gateway kill switch is ON — External Review AI access is blocked.'
                : 'Review gateway kill switch is OFF — External Review AI access is allowed.',
        ]);
    }

    /** Phase 5 — paginated evaluation runs (owner visibility). */
    public function evaluationRuns(Request $request): JsonResponse
    {
        $query = AiEvaluationRun::query()
            ->withCount('findings')
            ->orderByDesc('id');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }
        if ($request->filled('provider')) {
            $query->where('provider', $request->string('provider')->toString());
        }
        if ($request->filled('run_type')) {
            $query->where('run_type', $request->string('run_type')->toString());
        }

        $perPage = min(100, max(1, (int) $request->get('per_page', 25)));
        $page = $query->paginate($perPage);

        $page->getCollection()->transform(function (AiEvaluationRun $run) {
            return [
                'id' => $run->id,
                'provider' => $run->provider,
                'model' => $run->model,
                'model_version' => $run->model_version,
                'prompt_version' => $run->prompt_version,
                'evaluation_version' => $run->evaluation_version,
                'benchmark_set_version' => $run->benchmark_set_version,
                'run_type' => $run->run_type,
                'status' => $run->status,
                'total_cost' => (string) $run->total_cost,
                'started_at' => optional($run->started_at)?->toIso8601String(),
                'completed_at' => optional($run->completed_at)?->toIso8601String(),
                'trace_id' => $run->trace_id,
                'actor_user_id' => $run->actor_user_id,
                'findings_count' => (int) $run->findings_count,
                'created_at' => optional($run->created_at)?->toIso8601String(),
            ];
        });

        return response()->json($page);
    }

    /** Phase 5 — findings for one evaluation run. */
    public function evaluationFindings(int $id): JsonResponse
    {
        $run = AiEvaluationRun::query()->find($id);
        if (! $run) {
            return response()->json(['message' => 'Evaluation run not found.'], 404);
        }

        $findings = $run->findings()->orderBy('id')->get()->map(fn (AiEvaluationFinding $f) => [
            'id' => $f->id,
            'evaluation_run_id' => $f->evaluation_run_id,
            'subject_type' => $f->subject_type,
            'subject_id' => $f->subject_id,
            'dimension' => $f->dimension,
            'score' => (string) $f->score,
            'max_score' => (string) $f->max_score,
            'critique' => $f->critique,
            'statement_kind' => $f->statement_kind,
            'evidence_reference' => $f->evidence_reference,
            'created_at' => optional($f->created_at)?->toIso8601String(),
        ]);

        return response()->json([
            'run' => [
                'id' => $run->id,
                'provider' => $run->provider,
                'model' => $run->model,
                'model_version' => $run->model_version,
                'prompt_version' => $run->prompt_version,
                'evaluation_version' => $run->evaluation_version,
                'benchmark_set_version' => $run->benchmark_set_version,
                'run_type' => $run->run_type,
                'status' => $run->status,
                'total_cost' => (string) $run->total_cost,
                'trace_id' => $run->trace_id,
                'started_at' => optional($run->started_at)?->toIso8601String(),
                'completed_at' => optional($run->completed_at)?->toIso8601String(),
            ],
            'data' => $findings,
        ]);
    }
}
