<?php

namespace App\Services\Messaging;

use App\Models\Job;
use App\Models\Lead;
use App\Models\NextAction;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * A-21 / A-19 — Escalate critical customer-facing delivery failures to PM/owner.
 */
class NotificationFailureEscalationService
{
    /** Events that must reach the customer (or are operationally critical). */
    public const CRITICAL_EVENTS = [
        'quote_sent',
        'job_complete_pending_approval',
        'revision_requested',
        'site_visit_customer',
        'review_request_customer',
        'progress_update_customer',
        'invoice_sent',
        'payment_confirmed',
        'job_scheduled_customer',
    ];

    public function maybeEscalate(
        string $channel,
        string $triggerEvent,
        string $plainError,
        ?int $jobId = null,
        ?int $leadId = null,
        ?int $userId = null,
    ): ?NextAction {
        if (! in_array($triggerEvent, self::CRITICAL_EVENTS, true)) {
            return null;
        }

        try {
            $job = $jobId ? Job::with(['pm', 'lead'])->find($jobId) : null;
            $lead = $leadId ? Lead::find($leadId) : ($job?->lead);
            $pmId = $job?->pm_id ?? $lead?->assigned_pm_id;
            $ownerId = User::where('role', 'owner')->where('status', 'active')->orderBy('id')->value('id');
            $responsibleId = $pmId ?: $ownerId;
            if (! $responsibleId) {
                return null;
            }

            $subject = $job ?: $lead;
            if (! $subject) {
                return null;
            }

            $desc = strtoupper($channel).' delivery failed for '.$triggerEvent.': '.$plainError;

            $existing = NextAction::query()
                ->where('subject_type', $subject->getMorphClass())
                ->where('subject_id', $subject->id)
                ->where('escalation_rule', 'notification_delivery_failure')
                ->whereIn('status', ['pending', 'overdue'])
                ->where('action_description', 'like', '%'.$triggerEvent.'%')
                ->first();

            if ($existing) {
                $existing->update([
                    'action_description' => $desc,
                    'last_action_at' => now(),
                    'due_at' => now(),
                ]);

                return $existing;
            }

            return $subject->nextActions()->create([
                'action_description' => $desc,
                'responsible_role' => $pmId ? 'pm' : 'owner',
                'responsible_user_id' => $responsibleId,
                'due_at' => now(),
                'status' => 'pending',
                'escalation_rule' => 'notification_delivery_failure',
            ]);
        } catch (\Throwable $e) {
            Log::warning('Notification failure escalation skipped', [
                'error' => $e->getMessage(),
                'event' => $triggerEvent,
            ]);

            return null;
        }
    }
}
