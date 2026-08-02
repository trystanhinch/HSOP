<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\LearningGatewayAccessLog;
use App\Models\Setting;
use App\Models\User;
use App\Services\LearningGateway\LearningAiPrincipal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Milestone 6B Phase 1 — Owner Learning Gateway admin (human, not Learning AI).
 * Gated by role:owner only. Frontend UI deferred to a later phase.
 */
class AdminLearningGatewayController extends Controller
{
    public function __construct(private LearningAiPrincipal $principal) {}

    private function killSwitchKey(): string
    {
        return (string) config('learning_ai.kill_switch_setting_key', 'learning_gateway_kill_switch');
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
            $calls[$label] = LearningGatewayAccessLog::query()->where('created_at', '>=', $from)->count();
            $denied[$label] = LearningGatewayAccessLog::query()
                ->where('created_at', '>=', $from)
                ->where('outcome', 'denied')
                ->count();
        }

        $tokenQuery = $this->principal->activeTokensQuery();
        $activeTokens = (clone $tokenQuery)->count();
        $latestToken = (clone $tokenQuery)
            ->orderByDesc('last_used_at')
            ->orderByDesc('id')
            ->first(['id', 'name', 'last_used_at', 'created_at', 'expires_at']);

        $warningDays = max(1, (int) config('learning_ai.token_expiry_warning_days', 14));
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

        return response()->json([
            'identity' => [
                'role' => $this->principal->role(),
                'email' => $this->principal->email(),
                'label' => 'Learning AI (dedicated service identity)',
                'never_inherits' => ['ai_super_admin', 'external_review_ai'],
            ],
            'calls' => $calls,
            'denied' => $denied,
            'active_token_count' => $activeTokens,
            'tokens_nearing_expiration' => $nearing,
            'token_expiry_warning_days' => $warningDays,
            'kill_switch' => Setting::getBool($this->killSwitchKey(), false),
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
        $query = LearningGatewayAccessLog::query()->orderByDesc('id');

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
        $rows = $this->principal->activeTokensQuery()
            ->orderByDesc('id')
            ->get(['id', 'name', 'abilities', 'tokenable_type', 'tokenable_id', 'last_used_at', 'created_at', 'expires_at']);

        $data = $rows->map(function (PersonalAccessToken $t) {
            $abilities = $t->abilities;
            if (is_string($abilities)) {
                $abilities = json_decode($abilities, true) ?: [];
            }

            return [
                'id' => $t->id,
                'name' => $t->name,
                'actor_role' => $this->principal->role(),
                'abilities' => is_array($abilities) ? $abilities : [],
                'tokenable_type' => $t->tokenable_type,
                'tokenable_id' => $t->tokenable_id,
                'last_used_at' => optional($t->last_used_at)?->toIso8601String(),
                'created_at' => optional($t->created_at)?->toIso8601String(),
                'expires_at' => optional($t->expires_at)?->toIso8601String(),
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
        $token = $this->principal->activeTokensQuery()->where('id', $id)->first();
        if (! $token) {
            return response()->json(['message' => 'Learning token not found.'], 404);
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
            'action_type' => 'learning_gateway_token_revoked',
            'previous_value' => $snapshot,
            'new_value' => ['revoked' => true],
            'reason' => 'Owner revoked Learning AI token via admin API',
            'created_at' => now(),
        ]);

        return response()->json(['message' => 'Token revoked.', 'id' => $id]);
    }

    public function updateKillSwitch(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);

        $key = $this->killSwitchKey();
        $before = Setting::getBool($key, false);
        $after = (bool) $data['enabled'];
        Setting::setBool($key, $after);

        if ($after) {
            app(\App\Listeners\DispatchAlertOnKillSwitchEngaged::class)->handle(
                'learning',
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
            'action_type' => 'learning_gateway_kill_switch_changed',
            'previous_value' => ['learning_gateway_kill_switch' => $before],
            'new_value' => ['learning_gateway_kill_switch' => $after],
            'reason' => $after
                ? 'Owner engaged learning gateway kill switch'
                : 'Owner cleared learning gateway kill switch',
            'created_at' => now(),
        ]);

        return response()->json([
            'kill_switch' => $after,
            'message' => $after
                ? 'Learning gateway kill switch is ON — Learning AI access is blocked.'
                : 'Learning gateway kill switch is OFF — Learning AI access is allowed.',
        ]);
    }
}
