<?php

namespace App\Console\Commands;

use App\Services\Learning\AiConversationLogger;
use Illuminate\Console\Command;

class PurgeAiConversationLogsCommand extends Command
{
    protected $signature = 'learning:purge-ai-conversation-logs {--days= : Override retention days (default: setting or 365)}';

    protected $description = 'Purge ai_conversation_logs older than the configured retention window (default purge; no cold-storage archive yet)';

    public function handle(AiConversationLogger $logger): int
    {
        $override = $this->option('days');
        if ($override !== null && $override !== '') {
            $days = max(1, (int) $override);
            $cutoff = now()->subDays($days);
            $count = \App\Models\AiConversationLog::query()
                ->where('created_at', '<', $cutoff)
                ->delete();
            $this->info("Purged {$count} AI conversation log row(s) older than {$days} day(s) (CLI override).");

            return self::SUCCESS;
        }

        $days = AiConversationLogger::retentionDays();
        $count = $logger->purgeExpired();
        $this->info("Purged {$count} AI conversation log row(s) older than {$days} day(s) (setting ".AiConversationLogger::RETENTION_SETTING.').');
        $this->comment('Archive-to-cold-storage is not implemented — purge is the practical default until object-storage archival infra exists.');

        return self::SUCCESS;
    }
}
