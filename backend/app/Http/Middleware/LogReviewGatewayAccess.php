<?php

namespace App\Http\Middleware;

use App\Services\ReviewGateway\ReviewGatewayAccessLogger;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Milestone 6A Phase 1 — log successful / errored review-gateway tool calls.
 * Denials from EnsureReviewAiAbility are logged there (never reach this middleware).
 */
class LogReviewGatewayAccess
{
    public function __construct(private ReviewGatewayAccessLogger $logger) {}

    public function handle(Request $request, Closure $next): Response
    {
        try {
            /** @var Response $response */
            $response = $next($request);
        } catch (Throwable $e) {
            if (! $request->attributes->get('review_gateway_already_logged')) {
                $this->logger->log($request, 'error', 500, null, $e->getMessage());
            }
            throw $e;
        }

        if ($request->attributes->get('review_gateway_already_logged')) {
            return $response;
        }

        $status = $response->getStatusCode();
        $denialReason = $request->attributes->get('review_gateway_denial_reason');
        if ($status === 403) {
            $outcome = 'denied';
        } elseif ($status >= 500) {
            $outcome = 'error';
        } elseif ($status >= 400) {
            $outcome = 'error';
        } else {
            $outcome = 'success';
        }
        $count = $this->estimateRecordCount($response);

        $this->logger->log($request, $outcome, $status, $count, is_string($denialReason) ? $denialReason : null);

        if ($response instanceof JsonResponse) {
            $data = $response->getData(true);
            if (is_array($data) && ! isset($data['trace_id'])) {
                $data['trace_id'] = $request->attributes->get('review_gateway_trace_id');
                $response->setData($data);
            }
        }

        return $response;
    }

    private function estimateRecordCount(Response $response): ?int
    {
        if (! $response instanceof JsonResponse) {
            return null;
        }
        $data = $response->getData(true);
        if (! is_array($data)) {
            return null;
        }
        if (isset($data['meta']['total']) && is_numeric($data['meta']['total'])) {
            return (int) $data['meta']['total'];
        }
        if (isset($data['matches']) && is_array($data['matches'])) {
            return count($data['matches']);
        }
        if (isset($data['data']) && is_array($data['data'])) {
            return count($data['data']);
        }
        if (isset($data['results']) && is_array($data['results'])) {
            return count($data['results']);
        }
        if (isset($data['turns']) && is_array($data['turns'])) {
            return count($data['turns']);
        }

        return 1;
    }
}
