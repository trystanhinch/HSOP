<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Job;
use App\Models\Payout;
use App\Models\Quote;

class PayoutWorkflowService
{
    public function createPayoutsOnQuoteApproval(Quote $quote): void
    {
        $quote->loadMissing('job');
        $job = $quote->job;

        if (! $job || ! $job->contractor_id) {
            return;
        }

        Payout::firstOrCreate(
            ['job_id' => $job->id, 'payout_type' => 'contractor'],
            [
                'contractor_id' => $job->contractor_id,
                'payout_amount' => $quote->contractor_base_price ?? $job->contractor_submitted_price ?? 0,
                'status' => 'not_ready',
                'payout_type' => 'contractor',
            ]
        );

        if ($job->pm_id && ($quote->pm_amount ?? 0) > 0) {
            Payout::firstOrCreate(
                ['job_id' => $job->id, 'payout_type' => 'pm'],
                [
                    'contractor_id' => $job->pm_id,
                    'payout_amount' => $quote->pm_amount,
                    'status' => 'not_ready',
                    'payout_type' => 'pm',
                ]
            );
        }
    }

    public function markPayoutsReady(Job $job): void
    {
        Payout::where('job_id', $job->id)->update(['status' => 'ready_for_payout']);
    }

    public function syncPayoutReadiness(Job $job): ?Payout
    {
        $job->loadMissing(['invoice', 'payout']);

        $isPaid = in_array($job->status, ['paid_completed', 'paid', 'completed'], true);
        if (! $isPaid || ! $job->invoice || $job->invoice->status !== 'paid' || ! $job->contractor_id) {
            return $job->payout;
        }

        $payout = Payout::firstOrCreate(
            ['job_id' => $job->id, 'payout_type' => 'contractor'],
            [
                'contractor_id' => $job->contractor_id,
                'payout_amount' => $job->contractor_submitted_price ?? 0,
                'status' => 'ready_for_payout',
                'eligibility_status' => 'eligible_job_complete_paid',
                'payout_type' => 'contractor',
            ]
        );

        if (in_array($payout->status, ['not_ready', 'pending', 'hold_issue'], true)) {
            $payout->update(['status' => 'ready_for_payout']);
        }

        $this->markPayoutsReady($job);

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
                ['job_id' => $job->id, 'payout_type' => 'contractor'],
                [
                    'contractor_id' => $job->contractor_id,
                    'payout_amount' => $job->contractor_submitted_price ?? 0,
                    'status' => in_array($job->status, ['completed', 'paid_completed', 'paid'], true) ? 'ready_for_payout' : 'pending',
                    'eligibility_status' => 'eligible_invoice_paid',
                    'payout_type' => 'contractor',
                ]
            );
        }

        return $this->syncPayoutReadiness($job->fresh(['invoice']));
    }
}
