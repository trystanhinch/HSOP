<?php

namespace App\Services\LearningGateway;

use App\Models\LearningGatewayAccessLog;
use App\Models\Setting;
use App\Models\User;
use App\Services\ReviewGateway\SensitiveDataGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

class LearningGatewayAccessLogger
{
    public function isKillSwitchEngaged(): bool
    {
        $key = (string) config('learning_ai.kill_switch_setting_key', 'learning_gateway_kill_switch');

        return Setting::getBool($key, false);
    }

    public function ensureKillSwitchSettingExists(): void
    {
        $key = (string) config('learning_ai.kill_switch_setting_key', 'learning_gateway_kill_switch');
        if (! Setting::where('key', $key)->exists()) {
            Setting::setBool($key, false);
        }
    }

    public function log(
        Request $request,
        string $outcome,
        ?int $httpStatus = null,
        ?int $recordCount = null,
        ?string $denialReason = null,
        ?string $tool = null,
    ): LearningGatewayAccessLog {
        $user = $request->user();
        $token = $user?->currentAccessToken();
        $tokenId = null;
        $tokenName = null;
        if ($token instanceof PersonalAccessToken) {
            $tokenId = $token->id;
            $tokenName = $token->name;
        }

        $traceId = (string) ($request->attributes->get('learning_gateway_trace_id')
            ?? $request->headers->get('X-Correlation-Id')
            ?? Str::uuid());

        $request->attributes->set('learning_gateway_trace_id', $traceId);

        return LearningGatewayAccessLog::create([
            'actor_user_id' => $user instanceof User ? $user->id : null,
            'personal_access_token_id' => $tokenId,
            'token_name' => $tokenName,
            'ability' => (string) ($request->attributes->get('learning_gateway_ability')
                ?? config('learning_ai.required_ability', 'learning:read')),
            'tool' => $tool ?? $this->inferTool($request),
            'http_method' => $request->method(),
            'path' => '/'.$request->path(),
            'parameters' => $this->sanitizeParameters($request),
            'response_record_count' => $recordCount,
            'outcome' => $outcome,
            'http_status' => $httpStatus,
            'ip' => $request->ip(),
            'trace_id' => $traceId,
            'denial_reason' => $denialReason ?? $request->attributes->get('learning_gateway_denial_reason'),
            'created_at' => now(),
        ]);
    }

    private function inferTool(Request $request): ?string
    {
        $path = $request->path();
        if (str_contains($path, 'normalized-record')) {
            return 'normalized-record';
        }
        if (str_contains($path, 'tools/evidence') || str_ends_with($path, '/evidence')) {
            return 'evidence';
        }
        if (str_contains($path, 'recommendation')) {
            return 'recommendation';
        }
        if (str_contains($path, 'ping')) {
            return 'ping';
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function sanitizeParameters(Request $request): array
    {
        $guard = app(SensitiveDataGuard::class);
        $params = array_merge($request->query(), $request->route()?->parameters() ?? []);
        foreach ($params as $key => $value) {
            if (is_object($value) && method_exists($value, 'getKey')) {
                $params[$key] = $value->getKey();
            }
        }

        return $guard->scrub($params);
    }
}
