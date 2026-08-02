<?php

namespace App\Listeners;

use App\Models\AiActionLog;
use App\Services\Monitoring\AlertDispatcher;
use Illuminate\Support\Str;

/**
 * Phase 10 — AiActionLog with error set → AlertDispatcher (once per row, not per retry loop).
 */
class DispatchAlertOnAiActionError
{
    public function __construct(private AlertDispatcher $dispatcher) {}

    public function handle(AiActionLog $log): void
    {
        if ($log->error === null || $log->error === '') {
            return;
        }

        $this->dispatcher->dispatch('medium', 'AI action error: '.($log->trigger_event ?: 'unknown'), [
            'source' => 'ai.action_error',
            'ai_action_log_id' => $log->id,
            'trigger_event' => $log->trigger_event,
            'action_key' => $log->action_key,
            'trace_id' => $log->trace_id,
            'correlation_id' => $log->correlation_id,
            'error' => Str::limit((string) $log->error, 500),
        ]);
    }
}
