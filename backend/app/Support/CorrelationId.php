<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

/**
 * Milestone 6A Phase 10 — read the request-level correlation ID set by AssignCorrelationId.
 */
class CorrelationId
{
    public static function current(): ?string
    {
        $ctx = Log::sharedContext();
        $id = $ctx['correlation_id'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }
}
