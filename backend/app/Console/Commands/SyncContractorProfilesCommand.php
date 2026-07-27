<?php

namespace App\Console\Commands;

use App\Services\Contractors\ContractorDirectoryService;
use App\Services\Contractors\ContractorProfileCompleteness;
use App\Models\Contractor;
use Illuminate\Console\Command;

class SyncContractorProfilesCommand extends Command
{
    protected $signature = 'serviceop:sync-contractor-profiles
                            {--apply : Create missing profiles and link job/payout profile FKs}';

    protected $description = 'Audit A-04: ensure every contractor user has a profile and jobs/payouts link to contractors.id';

    public function handle(
        ContractorDirectoryService $directory,
        ContractorProfileCompleteness $completeness,
    ): int {
        $apply = (bool) $this->option('apply');
        $this->info($apply ? 'APPLY mode — writing profiles and links.' : 'DRY-RUN mode — no changes written.');

        $result = $directory->syncProfilesAndLinks($apply);

        if ($apply) {
            Contractor::withTestData()->with('user')->chunkById(100, function ($chunk) use ($completeness) {
                foreach ($chunk as $contractor) {
                    if (! in_array($contractor->state, ['suspended', 'deactivated'], true)) {
                        $completeness->refresh($contractor);
                    }
                }
            });
        }

        $this->table(['Metric', 'Count'], [
            ['Profiles created (or would create)', $result['profiles_created']],
            ['Jobs linked', $result['jobs_linked']],
            ['Payouts linked', $result['payouts_linked']],
            ['Manual review needed', count($result['manual_review'])],
            ['Directory count (non-deactivated)', $directory->directoryCount()],
        ]);

        if ($result['manual_review'] !== []) {
            $this->warn('Records needing manual review:');
            foreach ($result['manual_review'] as $row) {
                $this->line("  - {$row['type']}#{$row['id']} contractor_id={$row['contractor_id']} ({$row['reason']})");
            }
        }

        return self::SUCCESS;
    }
}
