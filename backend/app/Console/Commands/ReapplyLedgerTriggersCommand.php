<?php

namespace App\Console\Commands;

use App\Services\Ledger\LedgerAppendOnlyTriggers;
use Illuminate\Console\Command;

/**
 * Deliberate escape hatch to drop/re-apply ledger append-only triggers.
 * Requires --force so schema work cannot happen accidentally.
 */
class ReapplyLedgerTriggersCommand extends Command
{
    protected $signature = 'ledger:reapply-triggers
                            {--force : Required confirmation — refuse without it}
                            {--drop-only : Only DROP triggers (for ALTER / repair windows)}';

    protected $description = 'Drop and/or re-create append-only ledger triggers (requires --force)';

    public function handle(): int
    {
        if (! $this->option('force')) {
            $this->error('Refusing to modify triggers without --force (deliberate escape hatch).');
            $this->line('Examples:');
            $this->line('  php artisan ledger:reapply-triggers --force');
            $this->line('  php artisan ledger:reapply-triggers --drop-only --force');

            return self::FAILURE;
        }

        if (! LedgerAppendOnlyTriggers::isMysql()) {
            $this->error('ledger:reapply-triggers requires MySQL.');

            return self::FAILURE;
        }

        if ($this->option('drop-only')) {
            LedgerAppendOnlyTriggers::dropAll();
            $this->warn('Dropped all ledger append-only triggers. Re-apply when schema work is done.');

            return self::SUCCESS;
        }

        LedgerAppendOnlyTriggers::applyAll();
        $this->info('Re-applied append-only triggers on all four ledger tables.');

        $verify = LedgerAppendOnlyTriggers::verify();
        if (! $verify['ok']) {
            $this->error('Verify failed after re-apply — inspect with ledger:verify-triggers.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
