<?php

namespace App\Console\Commands;

use App\Services\Reporting\AiOpsReportService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class GenerateAiOpsReportCommand extends Command
{
    protected $signature = 'ops:generate-report {period=daily : daily or weekly} {--date=}';

    protected $description = 'Generate an AI ops daily/weekly summary report for admin review';

    public function handle(AiOpsReportService $reports): int
    {
        $period = $this->argument('period');
        if (! in_array($period, ['daily', 'weekly'], true)) {
            $this->error('period must be daily or weekly');

            return self::FAILURE;
        }

        $date = $this->option('date') ? Carbon::parse($this->option('date')) : now();
        $report = $reports->generate($period, $date);

        $this->info("Generated {$period} report #{$report->id} via {$report->provider}");
        $this->line(Str::limit($report->summary_text, 240));

        return self::SUCCESS;
    }
}
