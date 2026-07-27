<?php

namespace App\Console\Commands;

use App\Services\TestData\FlagTestDataService;
use Illuminate\Console\Command;

class FlagTestDataCommand extends Command
{
    protected $signature = 'serviceop:flag-test-data
                            {--dry-run : List matches without writing (default when --apply is omitted)}
                            {--apply : Persist is_test_data=true on matched records}';

    protected $description = 'Identify and flag known QA/placeholder records as is_test_data (never deletes).';

    public function handle(FlagTestDataService $service): int
    {
        $apply = (bool) $this->option('apply');
        // Default is dry-run; --dry-run is accepted explicitly for clarity.
        if ($this->option('dry-run') && $apply) {
            $this->error('Use either --dry-run (default) or --apply, not both.');

            return self::FAILURE;
        }

        $result = $service->run(apply: $apply);

        $this->info($apply ? 'APPLY mode — writing is_test_data flags' : 'DRY-RUN mode — no changes written');
        $this->newLine();

        $this->line('Test-flagged counts BEFORE:');
        $this->table(['table', 'count'], $this->rowsFromCounts($result['before']));

        $this->newLine();
        $this->line('Records to flag'.($apply ? ' (applied)' : ' (would flag)').':');
        foreach ($result['flagged'] as $table => $rows) {
            $this->line("  [{$table}] ".count($rows));
            foreach (array_slice($rows, 0, 25) as $row) {
                $this->line("    #{$row['id']} {$row['label']} — {$row['reason']}");
            }
            if (count($rows) > 25) {
                $this->line('    … '.(count($rows) - 25).' more');
            }
        }

        if ($result['needs_manual_review'] !== []) {
            $this->newLine();
            $this->warn('Needs manual review (NOT auto-flagged):');
            foreach ($result['needs_manual_review'] as $row) {
                $this->line("  [{$row['table']}] #{$row['id']} {$row['label']} — {$row['reason']}");
            }
        }

        $this->newLine();
        $this->line('Test-flagged counts AFTER:');
        $this->table(['table', 'count'], $this->rowsFromCounts($result['after']));

        $this->newLine();
        $this->info(sprintf(
            'Summary: would_flag=%d flagged=%d review=%d',
            $result['totals']['would_flag'],
            $result['totals']['flagged'],
            $result['totals']['review'],
        ));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, int>  $counts
     * @return list<array{0: string, 1: int}>
     */
    private function rowsFromCounts(array $counts): array
    {
        $rows = [];
        foreach ($counts as $table => $count) {
            $rows[] = [$table, $count];
        }

        return $rows;
    }
}
