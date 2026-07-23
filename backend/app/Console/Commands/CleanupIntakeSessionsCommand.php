<?php

namespace App\Console\Commands;

use App\Models\IntakeSession;
use Illuminate\Console\Command;

class CleanupIntakeSessionsCommand extends Command
{
    protected $signature = 'intake:cleanup-sessions {--hours= : Override expiry grace (default: already expired)}';

    protected $description = 'Delete expired public intake sessions that never converted to a Lead';

    public function handle(): int
    {
        $query = IntakeSession::query()
            ->whereNull('converted_lead_id')
            ->where('expires_at', '<', now());

        $count = (clone $query)->count();
        $query->delete();

        $this->info("Deleted {$count} expired unconverted intake session(s).");

        return self::SUCCESS;
    }
}
