<?php

namespace App\Http\Middleware;

use App\Services\ReviewGateway\ExternalReviewAiPrincipal;
use App\Services\ReviewGateway\ReviewGatewayAccessLogger;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Milestone 6A Phase 4 — External Review AI gateway gate.
 * Requires dedicated external_review_ai role AND an explicit review:* ability.
 * ai_super_admin (even with review:* on a crafted token) is denied.
 */
class EnsureReviewAiAbility
{
    public function __construct(
        private ReviewGatewayAccessLogger $logger,
        private ExternalReviewAiPrincipal $principal,
    ) {}

    public function handle(Request $request, Closure $next, ?string $ability = null): Response
    {
        $ability = $ability ?: (string) config('review_gateway.required_ability', 'review:read');
        $traceId = (string) ($request->headers->get('X-Correlation-Id') ?: Str::uuid());
        $request->attributes->set('review_gateway_trace_id', $traceId);
        $request->attributes->set('review_gateway_ability', $ability);

        $user = $request->user();
        $token = $user?->currentAccessToken();
        $abilities = is_object($token) && isset($token->abilities) && is_array($token->abilities)
            ? $token->abilities
            : [];

        // Explicit ability only — Sanctum's default ['*'] on human login tokens must NOT grant review access.
        $hasExplicitAbility = in_array($ability, $abilities, true);

        if ($this->logger->isKillSwitchEngaged()) {
            $this->logger->log(
                $request,
                'denied',
                403,
                null,
                'review_gateway_kill_switch',
            );
            $request->attributes->set('review_gateway_already_logged', true);

            return response()->json([
                'message' => 'Review gateway is disabled (kill switch).',
                'code' => 'review_gateway_kill_switch',
                'trace_id' => $traceId,
            ], 403);
        }

        if (! $this->principal->isExternalReviewAi($user)) {
            $this->logger->log(
                $request,
                'denied',
                403,
                null,
                'wrong_role:'.($user?->role ?? 'guest'),
            );
            $request->attributes->set('review_gateway_already_logged', true);

            return response()->json([
                'message' => 'Forbidden. Dedicated External Review AI identity required.',
                'code' => 'review_role_required',
                'required_role' => $this->principal->role(),
                'trace_id' => $traceId,
            ], 403);
        }

        if (! $hasExplicitAbility) {
            $this->logger->log(
                $request,
                'denied',
                403,
                null,
                'missing_ability:'.$ability,
            );
            $request->attributes->set('review_gateway_already_logged', true);

            return response()->json([
                'message' => 'Forbidden. Review AI ability required.',
                'code' => 'review_ability_required',
                'required_ability' => $ability,
                'trace_id' => $traceId,
            ], 403);
        }

        return $next($request);
    }
}
