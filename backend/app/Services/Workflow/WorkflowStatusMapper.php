<?php

namespace App\Services\Workflow;

class WorkflowStatusMapper
{
    public function canonicalize(string $entity, ?string $status): ?string
    {
        if ($status === null || $status === '') {
            return null;
        }

        $aliases = config("workflow.{$entity}.aliases", []);

        return $aliases[$status] ?? $status;
    }

    public function customerJobLabel(?string $status): string
    {
        if (! $status) {
            return 'Status pending';
        }

        $labels = config('workflow.job.customer_labels', []);
        $canonical = $this->canonicalize('job', $status);

        return $labels[$status]
            ?? $labels[$canonical]
            ?? str_replace('_', ' ', ucfirst((string) $canonical));
    }

    /**
     * @return list<string>
     */
    public function allowed(string $entity): array
    {
        return array_values(array_unique(array_merge(
            config("workflow.{$entity}.canonical", []),
            config("workflow.{$entity}.legacy", []),
        )));
    }

    public function isAllowed(string $entity, string $status): bool
    {
        return in_array($status, $this->allowed($entity), true);
    }
}
