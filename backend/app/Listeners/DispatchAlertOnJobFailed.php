<?php

namespace App\Listeners;

use App\Services\Monitoring\AlertDispatcher;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Str;

/**
 * Milestone 6A.4 — proof-of-concept alert wiring: permanent queue job failure → AlertDispatcher.
 */
class DispatchAlertOnJobFailed
{
    public function __construct(private AlertDispatcher $dispatcher) {}

    public function handle(JobFailed $event): void
    {
        $jobName = method_exists($event->job, 'resolveName')
            ? (string) $event->job->resolveName()
            : 'unknown';

        $this->dispatcher->dispatch('high', 'Queue job failed permanently: '.$jobName, [
            'source' => 'queue.job_failed',
            'connection' => $event->connectionName,
            'queue' => method_exists($event->job, 'getQueue') ? $event->job->getQueue() : null,
            'job' => $jobName,
            'exception' => Str::limit($event->exception->getMessage(), 500),
            'uuid' => method_exists($event->job, 'uuid') ? $event->job->uuid() : null,
        ]);
    }
}
