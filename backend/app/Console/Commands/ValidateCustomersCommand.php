<?php

namespace App\Console\Commands;

use App\Services\Customers\CustomerValidateService;
use Illuminate\Console\Command;

class ValidateCustomersCommand extends Command
{
    protected $signature = 'serviceop:validate-customers
                            {--apply : Write data_quality_flags, phone_normalized, and duplicate groups}';

    protected $description = 'Audit A-33: validate existing customer records (dry-run by default).';

    public function handle(CustomerValidateService $service): int
    {
        $apply = (bool) $this->option('apply');
        if ($apply) {
            $this->warn('APPLY mode — writing flags and duplicate groups.');
        } else {
            $this->info('DRY-RUN mode — no changes written.');
        }

        $result = $service->run($apply);

        $this->table(['Metric', 'Count'], [
            ['Customers scanned', $result['scanned']],
            ['Skipped (is_test_data)', $result['skipped_test_data']],
            ['With quality flags', $result['flagged_quality']],
            ['Duplicate groups', $result['duplicate_groups']],
            ['Customers in duplicate groups', $result['duplicate_members']],
        ]);

        if ($result['flags_by_reason'] !== []) {
            $this->newLine();
            $this->info('Flags by reason:');
            $rows = [];
            foreach ($result['flags_by_reason'] as $reason => $count) {
                $rows[] = [$reason, $count];
            }
            $this->table(['Reason', 'Count'], $rows);
        }

        return self::SUCCESS;
    }
}
