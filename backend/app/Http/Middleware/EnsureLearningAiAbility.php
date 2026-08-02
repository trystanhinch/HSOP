<?php

namespace App\Http\Middleware;

use App\Services\LearningGateway\LearningAiPrincipal;
use App\Services\LearningGateway\LearningGatewayAccessLogger;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Milestone 6B Phase 1 — Learning AI gateway gate.
 * Requires dedicated learning_ai role AND an explicit learning:* ability.
 */
class EnsureLearningAiAbility
{
    public function __construct(
        private LearningGatewayAccessLogger $logger,
        private LearningAiPrincipal $principal,
    ) {}

    public function handle(Request $request, Closure $next, ?string $ability = null): Response
    {
        $ability = $ability ?: (string) config('learning_ai.required_ability', 'learning:read');
        $traceId = (string) ($request->headers->get('X-Correlation-Id') ?: Str::uuid());
        $request->attributes->set('learning_gateway_trace_id', $traceId);
        $request->attributes->set('learning_gateway_ability', $ability);

        $user = $request->user();
        $token = $user?->currentAccessToken();
        $abilities = is_object($token) && isset($token->abilities) && is_array($token->abilities)
            ? $token->abilities
            : [];

        $hasExplicitAbility = in_array($ability, $abilities, true);

        if ($this->logger->isKillSwitchEngaged()) {
            $this->logger->log(
                $request,
                'denied',
                403,
                null,
                'learning_gateway_kill_switch',
            );
            $request->attributes->set('learning_gateway_already_logged', true);

            return response()->json([
                'message' => 'Learning gateway is disabled (kill switch).',
                'code' => 'learning_gateway_kill_switch',
                'trace_id' => $traceId,
            ], 403);
        }

        if (! $this->principal->isLearningAi($user)) {
            $this->logger->log(
                $request,
                'denied',
                403,
                null,
                'wrong_role:'.($user?->role ?? 'guest'),
            );
            $request->attributes->set('learning_gateway_already_logged', true);

            return response()->json([
                'message' => 'Forbidden. Dedicated Learning AI identity required.',
                'code' => 'learning_role_required',
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
            $request->attributes->set('learning_gateway_already_logged', true);

            return response()->json([
                'message' => 'Forbidden. Learning AI ability required.',
                'code' => 'learning_ability_required',
                'required_ability' => $ability,
                'trace_id' => $traceId,
            ], 403);
        }

        return $next($request);
    }
}
