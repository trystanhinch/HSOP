<?php

namespace App\Console\Commands;

use App\Services\Monitoring\GmailStalenessMonitor;
use Illuminate\Console\Command;

class MonitoringCheckGmailStalenessCommand extends Command
{
    protected $signature = 'monitoring:check-gmail-staleness';

    protected $description = 'Alert if Gmail lead intake last_fetched_at is older than the configured threshold (once per episode)';

    public function handle(GmailStalenessMonitor $monitor): int
    {
        $result = $monitor->check();
        $this->info(sprintf(
            'Gmail staleness check: checked=%d alerted=%d cleared=%d',
            $result['checked'],
            $result['alerted'],
            $result['cleared']
        ));

        return self::SUCCESS;
    }
}
