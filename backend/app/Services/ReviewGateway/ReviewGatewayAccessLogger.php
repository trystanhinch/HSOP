<?php

namespace App\Services\ReviewGateway;

use App\Models\ReviewGatewayAccessLog;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

class ReviewGatewayAccessLogger
{
    public function isKillSwitchEngaged(): bool
    {
        $key = (string) config('review_gateway.kill_switch_setting_key', 'review_gateway_kill_switch');

        return Setting::getBool($key, false);
    }

    public function ensureKillSwitchSettingExists(): void
    {
        $key = (string) config('review_gateway.kill_switch_setting_key', 'review_gateway_kill_switch');
        if (! Setting::where('key', $key)->exists()) {
            Setting::setBool($key, false);
        }
    }

    /**
     * @param  array<string, mixed>|null  $parameters
     */
    public function log(
        Request $request,
        string $outcome,
        ?int $httpStatus = null,
        ?int $recordCount = null,
        ?string $denialReason = null,
        ?string $tool = null,
    ): ReviewGatewayAccessLog {
        $user = $request->user();
        $token = $user?->currentAccessToken();
        $tokenId = null;
        $tokenName = null;
        if ($token instanceof PersonalAccessToken) {
            $tokenId = $token->id;
            $tokenName = $token->name;
        }

        $traceId = (string) ($request->attributes->get('review_gateway_trace_id')
            ?? $request->headers->get('X-Correlation-Id')
            ?? Str::uuid());

        $request->attributes->set('review_gateway_trace_id', $traceId);

        return ReviewGatewayAccessLog::create([
            'actor_user_id' => $user instanceof User ? $user->id : null,
            'personal_access_token_id' => $tokenId,
            'token_name' => $tokenName,
            'ability' => (string) ($request->attributes->get('review_gateway_ability')
                ?? config('review_gateway.required_ability', 'review:read')),
            'tool' => $tool ?? $this->inferTool($request),
            'http_method' => $request->method(),
            'path' => '/'.$request->path(),
            'parameters' => $this->sanitizeParameters($request),
            'response_record_count' => $recordCount,
            'outcome' => $outcome,
            'http_status' => $httpStatus,
            'ip' => $request->ip(),
            'trace_id' => $traceId,
            'denial_reason' => $denialReason ?? $request->attributes->get('review_gateway_denial_reason'),
            'created_at' => now(),
        ]);
    }

    private function inferTool(Request $request): ?string
    {
        $path = $request->path();
        if (str_contains($path, 'source-file')) {
            return 'source_file';
        }
        if (str_contains($path, 'source-search')) {
            return 'source_search';
        }
        if (str_contains($path, 'lead-journey')) {
            return 'lead_journey';
        }
        if (str_contains($path, 'ai-conversation-log')) {
            return 'ai_conversation_log';
        }
        if (str_contains($path, 'tools/search')) {
            return 'search';
        }
        if (str_contains($path, 'evaluation-finding')) {
            return 'evaluation_finding';
        }
        if (str_contains($path, 'evaluation-run')) {
            return 'evaluation_run';
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
        // Route model bindings may be objects — keep ids only.
        foreach ($params as $key => $value) {
            if (is_object($value) && method_exists($value, 'getKey')) {
                $params[$key] = $value->getKey();
            }
        }

        return $guard->scrub($params);
    }
}
