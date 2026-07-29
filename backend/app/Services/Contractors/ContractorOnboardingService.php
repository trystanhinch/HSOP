<?php

namespace App\Services\Contractors;

use App\Models\Contractor;
use App\Models\User;

/**
 * CT-12 — numbered onboarding checklist + readiness levels.
 * Reuses CT-03 compliance fields via ContractorProfileCompleteness.
 */
class ContractorOnboardingService
{
    public function __construct(
        private ContractorProfileCompleteness $completeness,
    ) {}

    /**
     * @return array{
     *   steps: list<array<string, mixed>>,
     *   progress: array{done: int, total: int, percent: int},
     *   readiness: array<string, array<string, mixed>>,
     *   profile_state: string
     * }
     */
    public function checklist(User $user): array
    {
        $profile = $this->completeness->ensureProfileForUser($user);
        $profile->loadMissing('user');

        $steps = [
            $this->step(1, 'profile', 'Complete profile', 'You', $this->profileStepStatus($profile), '/contractors/'.$profile->id, 'Add name, phone, email, and services.'),
            $this->step(2, 'wcb', 'Upload WCB coverage', 'You → Owner review', $this->docStatus($profile->wcb_status), '/contractors/'.$profile->id, 'Upload a valid WCB certificate.'),
            $this->step(3, 'insurance', 'Upload liability insurance', 'You → Owner review', $this->docStatus($profile->liability_insurance_status), '/contractors/'.$profile->id, 'Upload liability insurance for review.'),
            $this->step(4, 'stripe', 'Connect payout account (Stripe)', 'You', $this->stripeStatus($user), '/dashboard/contractor', 'Connect Stripe so payouts can be sent.'),
            $this->step(5, 'availability', 'Set availability & service area', 'You', $this->availabilityStatus($profile), '/my-availability', 'Set working hours, cities, and capacity.'),
        ];

        $done = count(array_filter($steps, fn ($s) => $s['status'] === 'complete'));
        $total = count($steps);

        return [
            'steps' => $steps,
            'progress' => [
                'done' => $done,
                'total' => $total,
                'percent' => $total > 0 ? (int) round(($done / $total) * 100) : 0,
            ],
            'readiness' => [
                'can_receive_site_visits' => $this->readiness(
                    $this->canReceiveSiteVisits($profile, $user),
                    $this->blockingForSiteVisits($profile, $user)
                ),
                'can_receive_jobs' => $this->readiness(
                    $this->canReceiveJobs($profile, $user),
                    $this->blockingForJobs($profile, $user)
                ),
                'can_receive_payouts' => $this->readiness(
                    (bool) $user->stripe_payout_ready && $user->stripe_account_id,
                    $user->stripe_payout_ready
                        ? []
                        : ['Connect Stripe and finish onboarding until status is “Ready for payouts”.']
                ),
            ],
            'profile_state' => (string) $profile->state,
            'contractor_id' => $profile->id,
        ];
    }

    private function canReceiveSiteVisits(Contractor $profile, User $user): bool
    {
        return $user->status === 'active'
            && $profile->state === 'approved'
            && $this->completeness->isCompliant($profile);
    }

    private function canReceiveJobs(Contractor $profile, User $user): bool
    {
        return $this->canReceiveSiteVisits($profile, $user);
    }

    /**
     * @return list<string>
     */
    private function blockingForSiteVisits(Contractor $profile, User $user): array
    {
        $blocks = [];
        if ($user->status !== 'active') {
            $blocks[] = 'Your login is inactive — contact an owner.';
        }
        if (in_array($profile->state, ['suspended', 'deactivated'], true)) {
            $blocks[] = 'Profile is '.$profile->state.' — owner must reactivate.';
        }
        foreach ($this->completeness->missingSteps($profile) as $step) {
            $blocks[] = $step['label'];
        }
        if ($profile->state !== 'approved' && $blocks === []) {
            $blocks[] = 'Profile must be approved before receiving site visits.';
        }

        return $blocks;
    }

    /**
     * @return list<string>
     */
    private function blockingForJobs(Contractor $profile, User $user): array
    {
        return $this->blockingForSiteVisits($profile, $user);
    }

    /**
     * @param  list<string>  $blocking
     * @return array{ready: bool, blocking: list<string>}
     */
    private function readiness(bool $ready, array $blocking): array
    {
        return ['ready' => $ready, 'blocking' => $ready ? [] : array_values($blocking)];
    }

    /**
     * @return array<string, mixed>
     */
    private function step(int $n, string $key, string $label, string $owner, string $status, string $href, string $nextAction): array
    {
        return [
            'number' => $n,
            'key' => $key,
            'label' => $label,
            'owner' => $owner,
            'status' => $status,
            'status_label' => match ($status) {
                'complete' => 'Complete',
                'pending_review' => 'Pending review',
                'rejected' => 'Needs re-upload',
                'in_progress' => 'In progress',
                default => 'Not started',
            },
            'href' => $href,
            'next_action' => $status === 'complete' ? null : $nextAction,
        ];
    }

    private function profileStepStatus(Contractor $profile): string
    {
        $missing = array_filter(
            $this->completeness->missingSteps($profile),
            fn ($s) => in_array($s['key'], ['legal_name', 'phone', 'email', 'services'], true)
        );

        return $missing === [] ? 'complete' : 'not_started';
    }

    private function docStatus(?string $status): string
    {
        $s = strtolower((string) $status);
        if (in_array($s, ['approved', 'verified', 'valid'], true)) {
            return 'complete';
        }
        if ($s === 'pending_review') {
            return 'pending_review';
        }
        if (in_array($s, ['rejected', 'expired'], true)) {
            return 'rejected';
        }

        return 'not_started';
    }

    private function stripeStatus(User $user): string
    {
        if ($user->stripe_payout_ready) {
            return 'complete';
        }
        if ($user->stripe_account_id) {
            return 'in_progress';
        }

        return 'not_started';
    }

    private function availabilityStatus(Contractor $profile): string
    {
        $services = array_filter($profile->services ?? []);
        $cities = array_filter($profile->cities ?? []);
        if ($services !== [] && $cities !== []) {
            return 'complete';
        }
        if ($services !== [] || $cities !== [] || $profile->working_hours) {
            return 'in_progress';
        }

        return 'not_started';
    }
}
