<?php

namespace App\Console\Commands;

use App\Services\Ledger\LedgerAppendOnlyTriggers;
use Illuminate\Console\Command;

/**
 * Read-only health check: all four ledger tables must have UPDATE + DELETE triggers.
 */
class VerifyLedgerTriggersCommand extends Command
{
    protected $signature = 'ledger:verify-triggers';

    protected $description = 'Verify append-only BEFORE UPDATE/DELETE triggers exist on the four ledger tables';

    public function handle(): int
    {
        if (! LedgerAppendOnlyTriggers::isMysql()) {
            $this->error('ledger:verify-triggers requires MySQL (current driver: '.config('database.default').').');

            return self::FAILURE;
        }

        $result = LedgerAppendOnlyTriggers::verify();
        $rows = [];
        foreach ($result['tables'] as $table => $info) {
            $rows[] = [
                $table,
                $info['update'] ? 'OK' : 'MISSING',
                $info['delete'] ? 'OK' : 'MISSING',
                $info['update_name'],
                $info['delete_name'],
            ];
        }

        $this->table(
            ['Table', 'BEFORE UPDATE', 'BEFORE DELETE', 'Update trigger', 'Delete trigger'],
            $rows
        );

        if ($result['ok']) {
            $this->info('All ledger append-only triggers are present.');

            return self::SUCCESS;
        }

        $this->error('One or more ledger triggers are missing. Re-apply with: php artisan ledger:reapply-triggers --force');

        return self::FAILURE;
    }
}
