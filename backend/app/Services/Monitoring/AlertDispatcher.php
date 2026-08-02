<?php

namespace App\Services\Monitoring;

use App\Models\Alert;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Milestone 6A.4 — alert routing skeleton.
 * Channels this phase: (1) alerts table, (2) Laravel slack log channel if configured.
 */
class AlertDispatcher
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function dispatch(string $severity, string $message, array $context = []): Alert
    {
        $severity = strtolower(trim($severity)) ?: 'info';
        $message = mb_substr($message, 0, 1000);

        $alert = Alert::create([
            'severity' => $severity,
            'message' => $message,
            'context' => $context,
            'created_at' => now(),
        ]);

        $this->maybeSlack($severity, $message, $context, $alert->id);

        return $alert;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function maybeSlack(string $severity, string $message, array $context, int $alertId): void
    {
        $url = (string) config('logging.channels.slack.url', '');
        if ($url === '') {
            return;
        }

        try {
            Log::channel('slack')->critical("[ServiceOP alert:{$severity}] {$message}", [
                'alert_id' => $alertId,
                'severity' => $severity,
                'context' => $context,
            ]);
        } catch (Throwable $e) {
            Log::warning('AlertDispatcher Slack channel failed', [
                'error' => $e->getMessage(),
                'alert_id' => $alertId,
            ]);
        }
    }
}
