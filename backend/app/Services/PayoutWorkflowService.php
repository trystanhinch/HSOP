<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Job;
use App\Models\Payout;

class PayoutWorkflowService
{
    public function syncPayoutReadiness(Job $job): ?Payout
    {
        $job->loadMissing(['invoice', 'payout']);

        if ($job->status !== 'completed' || ! $job->invoice || $job->invoice->status !== 'paid' || ! $job->contractor_id) {
            return $job->payout;
        }

        $payout = Payout::firstOrCreate(
            ['job_id' => $job->id],
            [
                'contractor_id' => $job->contractor_id,
                'payout_amount' => $job->contractor_submitted_price ?? 0,
                'status' => 'ready_for_payout',
                'eligibility_status' => 'eligible_job_complete_paid',
            ]
        );

        if (in_array($payout->status, ['not_ready', 'pending', 'hold_issue'], true)) {
            $payout->update(['status' => 'ready_for_payout']);
        }

        return $payout->fresh();
    }

    public function onInvoicePaid(Invoice $invoice): ?Payout
    {
        $job = $invoice->job()->first();
        if (! $job) {
            return null;
        }

        if ($job->contractor_id) {
            Payout::firstOrCreate(
                ['job_id' => $job->id],
                [
                    'contractor_id' => $job->contractor_id,
                    'payout_amount' => $job->contractor_submitted_price ?? 0,
                    'status' => $job->status === 'completed' ? 'ready_for_payout' : 'pending',
                    'eligibility_status' => 'eligible_invoice_paid',
                ]
            );
        }

        return $this->syncPayoutReadiness($job->fresh(['invoice']));
    }
}
