<?php

namespace App\Services\Monitoring;

use App\Models\GmailOauthToken;
use Carbon\Carbon;

/**
 * Phase 10 — Gmail intake staleness check (once per staleness episode).
 */
class GmailStalenessMonitor
{
    public function __construct(private AlertDispatcher $dispatcher) {}

    /**
     * @return array{checked: int, alerted: int, cleared: int}
     */
    public function check(): array
    {
        $hours = max(1, (int) config('monitoring.gmail_staleness_hours', 2));
        $threshold = Carbon::now()->subHours($hours);
        $alerted = 0;
        $cleared = 0;

        $tokens = GmailOauthToken::query()->get();
        foreach ($tokens as $token) {
            $last = $token->last_fetched_at;
            $isStale = $last === null || $last->lt($threshold);

            if (! $isStale) {
                if ($token->staleness_alerted) {
                    $token->forceFill(['staleness_alerted' => false])->save();
                    $cleared++;
                }
                continue;
            }

            if ($token->staleness_alerted) {
                continue;
            }

            $this->dispatcher->dispatch('high', 'Gmail lead intake is stale', [
                'source' => 'gmail.poll_stale',
                'mailbox_email' => $token->mailbox_email,
                'last_fetched_at' => $last?->toIso8601String(),
                'staleness_hours' => $hours,
            ]);

            $token->forceFill(['staleness_alerted' => true])->save();
            $alerted++;
        }

        return [
            'checked' => $tokens->count(),
            'alerted' => $alerted,
            'cleared' => $cleared,
        ];
    }

    /** Clear staleness episode flag after a successful fetch. */
    public function markFetchedFresh(string $mailboxEmail): void
    {
        GmailOauthToken::query()
            ->where('mailbox_email', $mailboxEmail)
            ->where('staleness_alerted', true)
            ->update(['staleness_alerted' => false]);
    }
}
