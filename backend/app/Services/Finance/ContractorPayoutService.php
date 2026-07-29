<?php

namespace App\Services\Finance;

use App\Models\Job;
use App\Models\Payout;
use App\Models\PayoutEvent;
use App\Models\User;
use App\Services\PayoutEligibilityService;

/**
 * CT-10 — contractor payout presentation (mirrors PM-03 commission clarity).
 * Shows only contractor amount + transfer result — never PM/company shares.
 */
class ContractorPayoutService
{
    public function __construct(
        private PmCommissionService $commission,
        private PayoutEligibilityService $eligibility,
        private FinancialLedgerService $ledger,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function present(Payout $payout): array
    {
        $base = $this->commission->present($payout);
        $state = $base['commission_state'];
        $nextStep = $this->nextStep($payout, $state, $base);

        // Strip PM/company-oriented fields; keep contractor-facing labels
        return [
            'id' => $payout->id,
            'job_id' => $payout->job_id,
            'job' => $payout->job?->only(['id', 'address', 'job_title', 'status']),
            'customer' => $payout->job?->customer?->only(['id', 'name']),
            'payout_amount' => (float) $payout->payout_amount,
            'contractor_amount' => (float) $payout->payout_amount,
            'payout_state' => $state,
            'payout_state_label' => $this->stateLabel($state),
            'amount_is_guaranteed' => (bool) ($base['amount_is_guaranteed'] ?? false),
            'approved_subtotal' => $base['approved_subtotal'] ?? null,
            'outstanding_condition' => $base['outstanding_condition'] ?? null,
            'not_ready_reasons' => $base['not_ready_reasons'] ?? [],
            'customer_payment_state' => $base['customer_payment_state'] ?? null,
            'customer_payment_state_label' => $base['customer_payment_state_label'] ?? null,
            'expected_payout_date' => $base['expected_payout_date'] ?? null,
            'paid_date' => $base['paid_date'] ?? null,
            'transfer_result' => $this->transferResult($payout),
            'next_step' => $nextStep,
            'eligibility_status' => $payout->eligibility_status,
            'status' => $payout->status,
        ];
    }

    public function stateLabel(string $state): string
    {
        return match ($state) {
            PmCommissionService::STATE_PROJECTED => 'Projected',
            PmCommissionService::STATE_EARNED => 'Earned',
            PmCommissionService::STATE_PAYABLE => 'Ready',
            PmCommissionService::STATE_PROCESSING => 'Processing',
            PmCommissionService::STATE_PAID => 'Paid',
            PmCommissionService::STATE_HELD => 'Held',
            PmCommissionService::STATE_REVERSED => 'Reversed',
            default => ucfirst($state),
        };
    }

    /**
     * Empty-list explanation for contractor payouts page.
     *
     * @return array{reason_code: string, message: string, next_action: string|null}
     */
    public function emptyReason(User $contractor): array
    {
        $jobCount = Job::query()->where('contractor_id', $contractor->id)->count();
        $completed = Job::query()
            ->where('contractor_id', $contractor->id)
            ->whereNotNull('customer_accepted_completion_at')
            ->count();
        $payoutCount = Payout::query()
            ->where('contractor_id', $contractor->id)
            ->where('payout_type', 'contractor')
            ->count();

        if ($payoutCount > 0) {
            return [
                'reason_code' => 'filtered',
                'message' => 'No payouts match this filter.',
                'next_action' => 'Clear filters to see all payouts.',
            ];
        }
        if ($jobCount === 0) {
            return [
                'reason_code' => 'no_jobs',
                'message' => 'No payouts yet — you have no assigned jobs.',
                'next_action' => 'Accept a site visit or job offer to get started.',
            ];
        }
        if ($completed === 0) {
            return [
                'reason_code' => 'no_completed_jobs',
                'message' => 'No payouts yet — none of your jobs have completed customer acceptance.',
                'next_action' => 'Finish in-progress work; payouts appear after completion acceptance and customer payment.',
            ];
        }

        return [
            'reason_code' => 'waiting_eligibility',
            'message' => 'Jobs are in progress toward payout eligibility (completion + customer payment).',
            'next_action' => 'Check each job’s completion and invoice status with your PM.',
        ];
    }

    /**
     * @param  array<string, mixed>  $base
     */
    private function nextStep(Payout $payout, string $state, array $base): ?string
    {
        return match ($state) {
            PmCommissionService::STATE_HELD => 'Payout is held. Contact support or your PM with the job number.',
            'failed', PmCommissionService::STATE_PROCESSING => ($payout->status === 'failed')
                ? 'Transfer failed — connect or fix Stripe payout account, then ask admin to retry.'
                : 'Transfer is processing. Check again later.',
            PmCommissionService::STATE_PROJECTED => $base['outstanding_condition']
                ? 'Waiting: '.$base['outstanding_condition']
                : 'Complete the job and wait for customer payment.',
            PmCommissionService::STATE_PAYABLE => 'Ready for payout — transfer will run on the scheduled date.',
            PmCommissionService::STATE_PAID => null,
            default => $payout->eligibility_status,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function transferResult(Payout $payout): array
    {
        return [
            'stripe_transfer_id' => $payout->stripe_transfer_id
                ? '…'.substr((string) $payout->stripe_transfer_id, -6)
                : null,
            'status' => $payout->status,
            'paid_date' => $payout->paid_date ? (string) $payout->paid_date : null,
            'failed' => $payout->status === 'failed',
            'held' => in_array($payout->status, ['on_hold', 'hold_issue'], true),
        ];
    }
}
