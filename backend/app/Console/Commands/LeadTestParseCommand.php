<?php

namespace App\Console\Commands;

use App\Models\ActivityTimelineEntry;
use App\Models\AiActionLog;
use App\Models\Lead;
use App\Models\NextAction;
use App\Services\LeadIntake\LeadEmailParser;
use App\Services\LeadIntake\LeadIntakePipeline;
use Illuminate\Console\Command;

class LeadTestParseCommand extends Command
{
    protected $signature = 'lead:test-parse
                            {--fixture= : Fixture name without extension (e.g. clean_lead)}
                            {--run-pipeline : Run full intake pipeline against the fixture}';

    protected $description = 'Parse a mock lead email fixture (optionally run the full intake pipeline)';

    public function handle(LeadEmailParser $parser, LeadIntakePipeline $pipeline): int
    {
        $fixture = $this->option('fixture');
        if (! $fixture) {
            $this->error('Provide --fixture=name (e.g. clean_lead)');

            return self::FAILURE;
        }

        $path = base_path("tests/fixtures/lead_emails/{$fixture}.txt");
        if (! is_file($path)) {
            $this->error("Fixture not found: {$path}");

            return self::FAILURE;
        }

        $raw = file_get_contents($path);
        $this->info("Fixture: {$fixture}");
        $this->line(str_repeat('-', 60));

        if (! $this->option('run-pipeline')) {
            $parsed = $parser->parse($raw);
            $this->line(json_encode($parsed->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $result = $pipeline->process($raw, sendNotifications: true);
        $this->line(json_encode($result->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        if ($result->lead) {
            $leadId = $result->lead->id;
            $this->newLine();
            $this->info('Database snapshot for lead #'.$leadId);
            $this->line(json_encode(
                Lead::with(['companySource', 'pendingNextAction'])->find($leadId)?->toArray(),
                JSON_PRETTY_PRINT
            ));
            $this->line('Next actions: '.NextAction::where('subject_id', $leadId)->where('subject_type', (new Lead)->getMorphClass())->count());
            $this->line('Timeline entries: '.ActivityTimelineEntry::where('subject_id', $leadId)->where('subject_type', (new Lead)->getMorphClass())->count());
            $this->line('AI action logs (lead_intake): '.AiActionLog::where('trigger_event', 'lead_intake')->count());
        }

        return self::SUCCESS;
    }
}
