<?php

namespace App\Http\Middleware;

use App\Services\LearningGateway\LearningGatewayAccessLogger;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Milestone 6B Phase 1 — log successful / errored learning-gateway calls.
 * Denials from EnsureLearningAiAbility are logged there.
 */
class LogLearningGatewayAccess
{
    public function __construct(private LearningGatewayAccessLogger $logger) {}

    public function handle(Request $request, Closure $next): Response
    {
        try {
            /** @var Response $response */
            $response = $next($request);
        } catch (Throwable $e) {
            if (! $request->attributes->get('learning_gateway_already_logged')) {
                $this->logger->log($request, 'error', 500, null, $e->getMessage());
            }
            throw $e;
        }

        if ($request->attributes->get('learning_gateway_already_logged')) {
            return $response;
        }

        $status = $response->getStatusCode();
        $denialReason = $request->attributes->get('learning_gateway_denial_reason');
        if ($status === 403) {
            $outcome = 'denied';
        } elseif ($status >= 400) {
            $outcome = 'error';
        } else {
            $outcome = 'success';
        }

        $this->logger->log(
            $request,
            $outcome,
            $status,
            $status < 400 ? 1 : null,
            is_string($denialReason) ? $denialReason : null,
        );

        if ($response instanceof JsonResponse) {
            $data = $response->getData(true);
            if (is_array($data) && ! isset($data['trace_id'])) {
                $data['trace_id'] = $request->attributes->get('learning_gateway_trace_id');
                $response->setData($data);
            }
        }

        return $response;
    }
}
