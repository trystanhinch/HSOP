<?php

namespace App\Console\Commands;

use App\Services\Learning\LearningRecordAssemblyService;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

class LearningAssembleRecordCommand extends Command
{
    protected $signature = 'learning:assemble-record {jobId : Job ID to assemble}';

    protected $description = 'Assemble (or re-assemble) a versioned canonical learning_records row for a job';

    public function handle(LearningRecordAssemblyService $assembler): int
    {
        $jobId = (int) $this->argument('jobId');
        if ($jobId < 1) {
            $this->error('jobId must be a positive integer.');

            return self::FAILURE;
        }

        try {
            $record = $assembler->assembleForJob($jobId);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error('Assembly failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Assembled learning_record #%d group=%s version=%d eligibility=%s (source %s:%s)',
            $record->id,
            $record->record_group_id,
            $record->version,
            $record->resolvedEligibilityStatus() ?? 'null',
            $record->eligibility_source_type,
            $record->eligibility_source_id
        ));

        if (! empty($record->missing_sources)) {
            $this->warn('Missing sources: '.implode(', ', $record->missing_sources));
        }

        return self::SUCCESS;
    }
}
