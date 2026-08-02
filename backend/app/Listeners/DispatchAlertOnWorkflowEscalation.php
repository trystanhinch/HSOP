<?php

namespace App\Listeners;

use App\Models\WorkflowEscalationLog;
use App\Services\Monitoring\AlertDispatcher;

/**
 * Phase 10 — WorkflowEscalationLog created → AlertDispatcher.
 * Severity from meta.severity if present; else stage "escalation" → high, otherwise medium.
 */
class DispatchAlertOnWorkflowEscalation
{
    public function __construct(private AlertDispatcher $dispatcher) {}

    public function handle(WorkflowEscalationLog $log): void
    {
        $meta = is_array($log->meta) ? $log->meta : [];
        $severity = isset($meta['severity']) && is_string($meta['severity']) && $meta['severity'] !== ''
            ? strtolower($meta['severity'])
            : ($log->stage === 'escalation' ? 'high' : 'medium');

        $this->dispatcher->dispatch($severity, 'Workflow escalation fired: '.($log->rule_key ?: 'unknown'), [
            'source' => 'workflow.escalation_fired',
            'workflow_escalation_log_id' => $log->id,
            'next_action_id' => $log->next_action_id,
            'rule_key' => $log->rule_key,
            'stage' => $log->stage,
        ]);
    }
}
