<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Milestone 6A.4 — ARC-07 foundation: request-level correlation ID + log context.
 * Does not yet propagate into Twilio/Resend/Stripe/OpenAI outbound calls.
 */
class AssignCorrelationId
{
    public const HEADER = 'X-Correlation-Id';

    public function handle(Request $request, Closure $next): Response
    {
        $incoming = $request->headers->get(self::HEADER);
        $correlationId = is_string($incoming) && trim($incoming) !== ''
            ? trim($incoming)
            : (string) Str::uuid();

        $request->headers->set(self::HEADER, $correlationId);
        $request->attributes->set('correlation_id', $correlationId);

        Log::shareContext([
            'correlation_id' => $correlationId,
        ]);

        /** @var Response $response */
        $response = $next($request);
        $response->headers->set(self::HEADER, $correlationId);

        return $response;
    }
}
