<?php

namespace App\Console\Commands;

use App\Services\Workflow\EscalationEngine;
use Illuminate\Console\Command;
use Throwable;

class WorkflowEscalationSweepCommand extends Command
{
    protected $signature = 'workflow:escalation-sweep';

    protected $description = 'Sweep overdue NextActions and fire reminder/escalation rules';

    public function handle(EscalationEngine $engine): int
    {
        try {
            $stats = $engine->run();
            $this->info('Escalation sweep complete');
            $this->line(json_encode($stats, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
