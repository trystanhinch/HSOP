<?php

namespace App\Console\Commands;

use App\Services\LeadCustomerResolver;
use Illuminate\Console\Command;

class RepairJobCustomers extends Command
{
    protected $signature = 'hsop:repair-data';

    protected $description = 'Repair jobs missing customer_id and fix contractor_id mismatches';

    public function handle(LeadCustomerResolver $resolver): int
    {
        $customers = $resolver->repairJobCustomers();
        $contractors = $resolver->repairContractorIds();

        $this->info('Repaired '.count($customers).' job(s) with missing customers.');
        foreach ($customers as $row) {
            $this->line("  Job #{$row['job_id']} ({$row['lead']}) → customer_id {$row['customer_id']}");
        }

        $this->info('Fixed '.count($contractors).' job(s) with invalid contractor_id.');
        foreach ($contractors as $row) {
            $this->line("  Job #{$row['job_id']} → contractor user_id {$row['contractor_user_id']}");
        }

        return self::SUCCESS;
    }
}
