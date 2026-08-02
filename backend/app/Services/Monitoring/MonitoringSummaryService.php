<?php

namespace App\Services\Monitoring;

use App\Models\AiActionLog;
use App\Models\EmailLog;
use App\Models\NextAction;
use App\Models\SmsLog;
use App\Models\StripeWebhookEvent;
use App\Models\WorkflowEscalationLog;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Milestone 6A.4 — read-only aggregation of existing operational signals.
 */
class MonitoringSummaryService
{
    /**
     * @return array<string, mixed>
     */
    public function summarize(?CarbonInterface $since = null): array
    {
        $since ??= now()->subHours((int) config('monitoring.summary_window_hours', 24));

        return [
            'window_hours' => (int) config('monitoring.summary_window_hours', 24),
            'since' => $since->toIso8601String(),
            'failed_jobs' => $this->failedJobsCount(),
            'sms_delivery_failures' => $this->smsFailures($since),
            'email_delivery_failures' => $this->emailFailures($since),
            'ai_action_errors' => $this->aiErrors($since),
            'stripe_webhook_failures' => $this->stripeFailures($since),
            'workflow_escalations_fired' => $this->escalationsFired($since),
            'overdue_next_actions' => $this->overdueNextActions(),
            'gmail_last_fetched_at' => $this->gmailLastFetchedAt(),
            'gmail_last_run_note' => 'Uses gmail_oauth_tokens.last_fetched_at (updated by GmailInboxFetcher after a successful poll). No separate scheduler heartbeat table exists.',
            'alerts_unacknowledged' => Schema::hasTable('alerts')
                ? (int) DB::table('alerts')->whereNull('acknowledged_at')->count()
                : 0,
        ];
    }

    private function failedJobsCount(): int
    {
        if (! Schema::hasTable('failed_jobs')) {
            return 0;
        }

        return (int) DB::table('failed_jobs')->count();
    }

    private function smsFailures(CarbonInterface $since): int
    {
        if (! Schema::hasTable('sms_logs')) {
            return 0;
        }

        return (int) SmsLog::query()
            ->whereIn('status', ['failed', 'provider_unavailable'])
            ->where('created_at', '>=', $since)
            ->count();
    }

    private function emailFailures(CarbonInterface $since): int
    {
        if (! Schema::hasTable('email_logs')) {
            return 0;
        }

        return (int) EmailLog::query()
            ->whereIn('status', ['failed', 'provider_unavailable'])
            ->where('created_at', '>=', $since)
            ->count();
    }

    private function aiErrors(CarbonInterface $since): int
    {
        if (! Schema::hasTable('ai_action_logs')) {
            return 0;
        }

        return (int) AiActionLog::query()
            ->whereNotNull('error')
            ->where('created_at', '>=', $since)
            ->count();
    }

    private function stripeFailures(CarbonInterface $since): int
    {
        if (! Schema::hasTable('stripe_webhook_events')) {
            return 0;
        }

        return (int) StripeWebhookEvent::query()
            ->where('status', 'failed')
            ->where('created_at', '>=', $since)
            ->count();
    }

    private function escalationsFired(CarbonInterface $since): int
    {
        if (! Schema::hasTable('workflow_escalation_logs')) {
            return 0;
        }

        return (int) WorkflowEscalationLog::query()
            ->where('fired_at', '>=', $since)
            ->count();
    }

    private function overdueNextActions(): int
    {
        if (! Schema::hasTable('next_actions')) {
            return 0;
        }

        // WorkflowEscalationLog has no unresolved flag — overdue NextActions are the live signal.
        return (int) NextAction::query()
            ->whereIn('status', ['pending', 'overdue'])
            ->whereNotNull('due_at')
            ->where('due_at', '<=', now())
            ->count();
    }

    private function gmailLastFetchedAt(): ?string
    {
        if (! Schema::hasTable('gmail_oauth_tokens')) {
            return null;
        }

        $value = DB::table('gmail_oauth_tokens')->max('last_fetched_at');

        return $value ? (string) $value : null;
    }
}
