<?php

namespace App\Services\Finance;

use App\Models\Job;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\PayoutEvent;
use App\Services\PayoutEligibilityService;
use App\Services\PricingService;

/**
 * Audit PM-03 — PM commission lifecycle presentation.
 *
 * States align with A-01 payout / ledger concepts:
 * Projected → Earned → Payable → Processing → Paid | Held | Reversed
 *
 * "Cleared payment" = invoice.status paid (same gate as PayoutEligibilityService / A-01).
 */
class PmCommissionService
{
    public const STATE_PROJECTED = 'projected';

    public const STATE_EARNED = 'earned';

    public const STATE_PAYABLE = 'payable';

    public const STATE_PROCESSING = 'processing';

    public const STATE_PAID = 'paid';

    public const STATE_HELD = 'held';

    public const STATE_REVERSED = 'reversed';

    public function __construct(
        private PricingService $pricing,
        private PayoutEligibilityService $eligibility,
        private FinancialLedgerService $ledger,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function present(Payout $payout): array
    {
        $payout->loadMissing([
            'job:id,address,job_title,customer_id,completed_at,customer_accepted_completion_at,pm_id,status',
            'job.customer:id,name,email,phone',
            'job.invoice:id,job_id,status,subtotal,amount,balance',
            'job.quote:id,job_id,status,customer_price_before_gst,pm_pct,pm_amount,subtotal',
        ]);

        $job = $payout->job;
        $check = $job ? $this->eligibility->checkEligibility($job) : null;
        $state = $this->resolveState($payout, $job, $check);
        $subtotal = $this->approvedSubtotal($job);
        $pmPct = $this->pmPercentage($job, $payout);
        $outstanding = $this->outstandingCondition($payout, $job, $check);
        $paymentState = $this->customerPaymentState($job);
        $paidConfirmed = $this->isPaidConfirmed($payout);

        return array_merge($payout->toArray(), [
            'commission_state' => $state,
            'commission_state_label' => $this->stateLabel($state),
            'amount_is_guaranteed' => in_array($state, [self::STATE_PAYABLE, self::STATE_PROCESSING, self::STATE_PAID], true),
            'approved_subtotal' => $subtotal,
            'pm_percentage' => $pmPct,
            'commission_amount' => (float) $payout->payout_amount,
            'outstanding_condition' => $outstanding,
            'not_ready_reasons' => $this->ledger->notReadyReasons($payout, $job),
            'customer_payment_state' => $paymentState,
            'customer_payment_state_label' => $this->paymentStateLabel($paymentState),
            'completion_accepted' => (bool) $job?->customer_accepted_completion_at,
            'expected_payout_date' => $payout->scheduled_for?->toDateString()
                ?? ($payout->payout_due_date ? (string) $payout->payout_due_date : null),
            'paid_date' => $payout->paid_date ? (string) $payout->paid_date : null,
            'paid_confirmed' => $paidConfirmed,
            'payment_confirmation' => $paidConfirmed
                ? ($payout->stripe_transfer_id ? 'stripe_transfer' : 'manual_recorded')
                : null,
            'eligibility_check' => $check,
            'latest_payout_event' => PayoutEvent::query()
                ->where('payout_id', $payout->id)
                ->latest('id')
                ->first(['id', 'event_type', 'notes', 'created_at']),
        ]);
    }

    /**
     * @param  array{eligible?: bool, status?: string, reason?: string}|null  $check
     */
    public function resolveState(Payout $payout, ?Job $job = null, ?array $check = null): string
    {
        $status = (string) $payout->status;

        if ($status === 'reversed' || $this->hasReversedEvent($payout)) {
            return self::STATE_REVERSED;
        }
        if (in_array($status, ['on_hold', 'hold_issue'], true)) {
            return self::STATE_HELD;
        }
        if ($status === 'paid' && $this->isPaidConfirmed($payout)) {
            return self::STATE_PAID;
        }
        // Paid status without transfer/manual confirmation should not claim Paid
        if ($status === 'paid' && ! $this->isPaidConfirmed($payout)) {
            return self::STATE_PROCESSING;
        }
        if (in_array($status, ['queued', 'in_transit', 'pending', 'failed'], true)) {
            return self::STATE_PROCESSING;
        }

        $job = $job ?: $payout->job;
        $check = $check ?? ($job ? $this->eligibility->checkEligibility($job) : ['eligible' => false]);
        $bothGatesMet = (bool) ($check['eligible'] ?? false);

        if ($bothGatesMet) {
            if (in_array($status, ['ready_for_payout', 'approved', 'scheduled'], true)) {
                return self::STATE_PAYABLE;
            }
            if ($status === 'eligible') {
                return self::STATE_EARNED;
            }

            // Both gates met but row still showing a waiting_* label — treat as Payable
            return self::STATE_PAYABLE;
        }

        // Quote-approved / incomplete eligibility — never "earned"
        return self::STATE_PROJECTED;
    }

    public function isClearedPayment(?Job $job): bool
    {
        if (! $job?->invoice) {
            return false;
        }
        if ($job->invoice->status !== 'paid') {
            return false;
        }

        // Match A-01 / InvoicePaymentService: paid invoices are cleared; reject unpaid clear flags if present
        $uncleared = Payment::query()
            ->where('invoice_id', $job->invoice->id)
            ->where('cleared_status', false)
            ->exists();

        return ! $uncleared;
    }

    public function isPaidConfirmed(Payout $payout): bool
    {
        if ($payout->status !== 'paid') {
            return false;
        }
        if (! empty($payout->stripe_transfer_id)) {
            return true;
        }
        // Manual e-transfer / recorded payment (A-03 pattern): paid_date + method or payout event
        if ($payout->paid_date) {
            return true;
        }

        return PayoutEvent::query()
            ->where('payout_id', $payout->id)
            ->where('event_type', 'paid')
            ->exists();
    }

    /**
     * @param  array{eligible?: bool, status?: string, reason?: string}|null  $check
     */
    public function outstandingCondition(Payout $payout, ?Job $job, ?array $check): ?string
    {
        $state = $this->resolveState($payout, $job, $check);
        if (in_array($state, [
            self::STATE_PAYABLE, self::STATE_PROCESSING, self::STATE_PAID, self::STATE_EARNED,
        ], true)) {
            return null;
        }
        if ($state === self::STATE_HELD) {
            return 'Payout is on hold';
        }
        if ($state === self::STATE_REVERSED) {
            return 'Commission was reversed';
        }

        $status = $check['status'] ?? $payout->status;
        $job = $job ?: $payout->job;

        return match ($status) {
            'waiting_for_revision_closure' => 'waiting on open revision to close',
            'waiting_for_completion_acceptance' => 'waiting on job completion acceptance',
            'waiting_for_payment' => 'waiting on customer payment',
            default => ! $job?->customer_accepted_completion_at
                ? 'waiting on job completion acceptance'
                : (! $this->isClearedPayment($job)
                    ? 'waiting on customer payment'
                    : ($payout->eligibility_status ?: 'eligibility incomplete')),
        };
    }

    public function stateLabel(string $state): string
    {
        return match ($state) {
            self::STATE_PROJECTED => 'Projected',
            self::STATE_EARNED => 'Earned',
            self::STATE_PAYABLE => 'Payable',
            self::STATE_PROCESSING => 'Processing',
            self::STATE_PAID => 'Paid',
            self::STATE_HELD => 'Held',
            self::STATE_REVERSED => 'Reversed',
            default => ucfirst($state),
        };
    }

    /**
     * @return list<string>
     */
    public function statusesForCommissionState(string $commissionState): array
    {
        return match ($commissionState) {
            self::STATE_PROJECTED => [
                'waiting_for_completion_acceptance',
                'waiting_for_payment',
                'waiting_for_revision_closure',
                'not_ready',
                'not_eligible',
            ],
            self::STATE_EARNED => ['eligible'],
            self::STATE_PAYABLE => ['scheduled', 'ready_for_payout', 'approved'],
            self::STATE_PROCESSING => ['queued', 'in_transit', 'pending', 'failed'],
            self::STATE_PAID => ['paid'],
            self::STATE_HELD => ['on_hold', 'hold_issue'],
            self::STATE_REVERSED => ['reversed'],
            default => [],
        };
    }

    private function approvedSubtotal(?Job $job): float
    {
        if (! $job) {
            return 0.0;
        }

        return (float) ($job->invoice?->subtotal
            ?? $job->quote?->customer_price_before_gst
            ?? $job->quote?->subtotal
            ?? 0);
    }

    private function pmPercentage(?Job $job, Payout $payout): float
    {
        if ($job?->quote?->pm_pct !== null) {
            return (float) $job->quote->pm_pct;
        }
        if ($job) {
            $split = $this->pricing->splitFromJob($job);

            return (float) $split['pm_pct'];
        }

        return 0.0;
    }

    private function customerPaymentState(?Job $job): string
    {
        if (! $job?->invoice) {
            return 'no_invoice';
        }
        if ($this->isClearedPayment($job)) {
            return 'cleared';
        }

        return (string) $job->invoice->status;
    }

    private function paymentStateLabel(string $state): string
    {
        return match ($state) {
            'cleared' => 'Cleared / paid',
            'no_invoice' => 'No invoice yet',
            'draft' => 'Invoice draft',
            'invoice_sent', 'sent' => 'Invoice sent',
            'awaiting_payment' => 'Awaiting payment',
            'partially_paid' => 'Partially paid',
            'paid' => 'Paid (clearing)',
            default => str_replace('_', ' ', $state),
        };
    }

    private function hasReversedEvent(Payout $payout): bool
    {
        return PayoutEvent::query()
            ->where('payout_id', $payout->id)
            ->whereIn('event_type', ['reversed'])
            ->exists();
    }
}
