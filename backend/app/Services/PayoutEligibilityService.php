<?php

namespace App\Services;

use App\Models\AiActionLog;
use App\Models\Job;
use App\Models\Payout;
use App\Models\RevisionRequest;
use App\Models\Setting;
use App\Services\Accounting\BusinessDayCalculator;
use App\Services\PricingService;

/**
 * Determines payout eligibility and schedules transfers (no Stripe execution).
 */
class PayoutEligibilityService
{
    public function __construct(
        private PricingService $pricing,
        private BusinessDayCalculator $businessDays,
    ) {}

    public function evaluateForJob(Job $job): array
    {
        $job->loadMissing(['invoice', 'quote', 'revisionRequests', 'contractor', 'pm']);

        $before = Payout::where('job_id', $job->id)->get()->keyBy(fn ($p) => $p->split_type ?: $p->payout_type);

        $invoicePaid = $job->invoice && $job->invoice->status === 'paid';
        $completionAccepted = (bool) $job->customer_accepted_completion_at;
        $openRevision = RevisionRequest::where('job_id', $job->id)
            ->whereIn('status', ['open', 'pending', 'in_progress'])
            ->exists();

        $status = 'not_eligible';
        $reason = 'Preconditions not met';

        if ($openRevision) {
            $status = 'waiting_for_revision_closure';
            $reason = 'Open revision request';
        } elseif (! $completionAccepted) {
            $status = 'waiting_for_completion_acceptance';
            $reason = 'Completion not accepted';
        } elseif (! $invoicePaid) {
            $status = 'waiting_for_payment';
            $reason = 'Invoice not paid';
        } else {
            $status = 'eligible';
            $reason = 'Payment received, completion accepted, no open revision';
        }

        $amounts = $this->splitAmounts($job);
        $payouts = [];

        foreach ($amounts as $splitType => $row) {
            if ($row['amount'] <= 0) {
                continue;
            }
            if ($splitType !== 'company' && ! $row['user_id']) {
                continue;
            }

            $payout = Payout::firstOrNew([
                'job_id' => $job->id,
                'payout_type' => $splitType,
            ]);

            // Don't regress already-paid / in-transit / held / queued payouts
            if (in_array($payout->status, ['paid', 'in_transit', 'on_hold', 'queued', 'failed'], true) && $status === 'eligible') {
                $payouts[] = $payout;

                continue;
            }

            $payout->fill([
                'contractor_id' => $splitType === 'contractor' ? $row['user_id'] : ($splitType === 'pm' ? $row['user_id'] : null),
                'pm_id' => $splitType === 'pm' ? $row['user_id'] : null,
                'payout_amount' => $row['amount'],
                'split_type' => $splitType,
                'payout_type' => $splitType,
                'eligibility_status' => $reason,
            ]);

            if ($status === 'eligible') {
                $days = (int) Setting::get('payout_schedule_business_days', (string) config('payment.payout.schedule_business_days', 2));
                $eligibleAt = now();
                $scheduled = $this->businessDays->addBusinessDays($eligibleAt, $days);

                $payout->status = 'scheduled';
                $payout->eligible_at = $eligibleAt;
                $payout->scheduled_for = $scheduled->toDateString();
                $payout->payout_due_date = $scheduled->toDateString();
            } else {
                $payout->status = $status;
                $payout->eligible_at = null;
                $payout->scheduled_for = null;
            }

            $payout->save();
            $payouts[] = $payout->fresh();
        }

        if ($status === 'eligible') {
            try {
                app(\App\Services\Reviews\ReviewRequestService::class)->requestIfEligible($job->fresh([
                    'lead', 'customer', 'invoice', 'pm',
                ]));
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Review request failed after eligibility', [
                    'job_id' => $job->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        AiActionLog::create([
            'trigger_event' => 'payout_eligibility_check',
            'actor_id' => auth()->id()
                ?? \App\Models\User::where('role', 'owner')->value('id')
                ?? \App\Models\User::where('email', 'ai-super-admin@serviceop.system')->value('id'),
            'data_viewed' => [
                'job_id' => $job->id,
                'invoice_id' => $job->invoice?->id,
                'invoice_status' => $job->invoice?->status,
                'completion_accepted' => $completionAccepted,
                'open_revision' => $openRevision,
                'amounts' => $amounts,
            ],
            'decision' => $status === 'eligible' ? 'eligible' : 'not_eligible',
            'action_taken' => 'payout_status_sync',
            'status_before' => json_encode($before->map(fn ($p) => $p->status)->all()),
            'status_after' => json_encode(collect($payouts)->mapWithKeys(fn ($p) => [($p->split_type ?: $p->payout_type) => $p->status])->all()),
            'rule_applied' => 'payment_received + completion_accepted + no_open_revision → schedule +'.$this->scheduleDays().' business days',
            'required_human_approval' => false,
        ]);

        return [
            'eligible' => $status === 'eligible',
            'status' => $status,
            'reason' => $reason,
            'payouts' => $payouts,
        ];
    }

    private function scheduleDays(): int
    {
        return (int) Setting::get('payout_schedule_business_days', (string) config('payment.payout.schedule_business_days', 2));
    }

    /**
     * Reuse PricingService 80/10/10 (or job overrides) against customer subtotal.
     *
     * @return array<string, array{user_id: ?int, amount: float, pct: float}>
     */
    private function splitAmounts(Job $job): array
    {
        $split = $this->pricing->splitFromJob($job);
        $subtotal = (float) ($job->invoice?->subtotal
            ?? $job->quote?->customer_price_before_gst
            ?? 0);

        if ($subtotal <= 0 && $job->contractor_submitted_price) {
            $calc = $this->pricing->fromContractorPrice((float) $job->contractor_submitted_price, true, $job);
            $subtotal = $calc['customer_subtotal'];
        }

        $contractorAmount = round($subtotal * ($split['contractor_pct'] / 100), 2);
        // Prefer explicit contractor net from quote when present
        if ($job->quote?->contractor_base_price) {
            $contractorAmount = (float) $job->quote->contractor_base_price;
        } elseif ($job->contractor_submitted_price) {
            $contractorAmount = (float) $job->contractor_submitted_price;
        }

        $pmAmount = round($subtotal * ($split['pm_pct'] / 100), 2);
        $companyAmount = round($subtotal * ($split['company_pct'] / 100), 2);

        return [
            'contractor' => [
                'user_id' => $job->contractor_id,
                'amount' => $contractorAmount,
                'pct' => $split['contractor_pct'],
            ],
            'pm' => [
                'user_id' => $job->pm_id,
                'amount' => $pmAmount,
                'pct' => $split['pm_pct'],
            ],
            'company' => [
                'user_id' => null,
                'amount' => $companyAmount,
                'pct' => $split['company_pct'],
            ],
        ];
    }
}
