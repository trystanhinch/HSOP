<?php

namespace App\Console\Commands;

use App\Services\Gmail\GmailInboxFetcher;
use Illuminate\Console\Command;
use Throwable;

class GmailFetchLeadsCommand extends Command
{
    protected $signature = 'gmail:fetch-leads
                            {--mailbox= : Override mailbox email}';

    protected $description = 'Poll the connected Gmail inbox and run new messages through the lead intake pipeline';

    public function handle(GmailInboxFetcher $fetcher, \App\Services\Gmail\GmailOAuthService $oauth): int
    {
        if (! config('gmail.enabled', true)) {
            $this->info('Gmail fetch disabled (GMAIL_FETCH_ENABLED=false).');

            return self::SUCCESS;
        }

        if (! $oauth->isConfigured()) {
            $this->warn('Gmail OAuth credentials not configured — skipping.');

            return self::SUCCESS;
        }

        $status = $oauth->connectionStatus();
        if (! ($status['connected'] ?? false)) {
            $this->warn('Gmail inbox not connected yet — skipping. Complete Settings → Lead Inbox → Connect Gmail.');

            return self::SUCCESS;
        }

        try {
            $stats = $fetcher->fetchAndProcess($this->option('mailbox') ?: null);
            $this->info('Gmail fetch complete');
            $this->line(json_encode($stats, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        } catch (Throwable $e) {
            // Never echo tokens/secrets — message only.
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
