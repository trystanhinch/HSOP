<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Job;
use App\Models\Payout;
use App\Models\Quote;

/**
 * Legacy entry points — delegates eligibility/scheduling to PayoutEligibilityService.
 */
class PayoutWorkflowService
{
    public function __construct(private PayoutEligibilityService $eligibility) {}

    public function createPayoutsOnQuoteApproval(Quote $quote): void
    {
        $quote->loadMissing('job');
        $job = $quote->job;
        if (! $job) {
            return;
        }

        $this->eligibility->evaluateForJob($job->fresh(['invoice', 'quote', 'revisionRequests', 'contractor', 'pm']));
    }

    public function markPayoutsReady(Job $job): void
    {
        $this->eligibility->evaluateForJob($job->fresh(['invoice', 'quote', 'revisionRequests', 'contractor', 'pm']));
    }

    public function syncPayoutReadiness(Job $job): ?Payout
    {
        $result = $this->eligibility->evaluateForJob($job->fresh(['invoice', 'quote', 'revisionRequests', 'contractor', 'pm']));
        $contractor = collect($result['payouts'])->first(fn ($p) => ($p->split_type ?: $p->payout_type) === 'contractor');

        return $contractor;
    }

    public function onInvoicePaid(Invoice $invoice): ?Payout
    {
        $job = $invoice->job()->first();
        if (! $job) {
            return null;
        }

        return $this->syncPayoutReadiness($job);
    }
}
