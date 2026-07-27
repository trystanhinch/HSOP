<?php

namespace App\Services\Contractors;

use App\Models\Contractor;
use App\Models\Job;
use App\Models\Payout;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Audit A-04 — directory queries + backfill orchestration (detection/apply).
 */
class ContractorDirectoryService
{
    public function __construct(
        private readonly ContractorProfileCompleteness $completeness,
    ) {}

    /**
     * Directory definition shared with Admin Dashboard count.
     * Production (non-test) contractor profiles that are not deactivated.
     */
    public function directoryQuery()
    {
        return Contractor::productionOnly()
            ->where('state', '!=', 'deactivated');
    }

    public function directoryCount(): int
    {
        return $this->directoryQuery()->count();
    }

    /**
     * Enrich a contractor for list/detail API payloads.
     *
     * @return array<string, mixed>
     */
    public function serialize(Contractor $contractor, string $viewerRole = 'owner'): array
    {
        if (! $contractor->relationLoaded('user')) {
            $contractor->load([
                'user:id,name,email,phone,status,stripe_account_id,stripe_onboarding_status,stripe_payout_ready,is_test_data',
            ]);
        }

        $user = $contractor->user;
        $missing = $this->completeness->missingSteps($contractor);
        $activeJobCount = Job::productionOnly()
            ->where(function ($q) use ($contractor, $user) {
                $q->where('contractor_profile_id', $contractor->id);
                if ($user) {
                    $q->orWhere('contractor_id', $user->id);
                }
            })
            ->whereNotIn('status', ['cancelled', 'completed', 'paid_completed'])
            ->count();

        $displayName = $contractor->legal_name
            ?: $contractor->operating_name
            ?: $contractor->contact_name
            ?: $user?->name
            ?: '—';

        $payload = [
            'id' => $contractor->id,
            'user_id' => $contractor->user_id,
            'name' => $displayName,
            'legal_name' => $contractor->legal_name,
            'operating_name' => $contractor->operating_name,
            'contact_name' => $contractor->contact_name,
            'phone' => $contractor->phone ?: $user?->phone,
            'email' => $contractor->email ?: $user?->email,
            'services' => $contractor->services ?? [],
            'cities' => $contractor->cities ?? [],
            'territory' => $contractor->cities ?? [],
            'wcb_status' => $contractor->wcb_status,
            'liability_insurance_status' => $contractor->liability_insurance_status,
            'approval_status' => $contractor->approval_status,
            'state' => $contractor->state,
            'missing_steps' => $missing,
            'active_job_count' => $activeJobCount,
            'operational_warnings' => $this->completeness->operationalWarnings($contractor),
            'availability_status' => $activeJobCount > 0 ? 'on_job' : 'available',
            'user' => $user ? [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'status' => $user->status,
            ] : null,
        ];

        if ($viewerRole === 'owner') {
            $payload['stripe'] = [
                'account_id' => $user?->stripe_account_id,
                'onboarding_status' => $user?->stripe_onboarding_status,
                'payout_ready' => (bool) ($user?->stripe_payout_ready),
                'status_label' => $this->stripeLabel($user),
            ];
            $payload['admin_notes'] = $contractor->admin_notes ?? null;
        }

        return $payload;
    }

    private function stripeLabel(?User $user): string
    {
        if (! $user?->stripe_account_id) {
            return 'Not connected';
        }
        if ($user->stripe_payout_ready) {
            return 'Ready for payouts';
        }

        return $user->stripe_onboarding_status
            ? 'Onboarding: '.$user->stripe_onboarding_status
            : 'Connected — setup incomplete';
    }

    /**
     * Sync missing profiles and job/payout profile FKs.
     *
     * @return array{profiles_created: int, jobs_linked: int, payouts_linked: int, manual_review: list<array>}
     */
    public function syncProfilesAndLinks(bool $apply = true): array
    {
        $created = 0;
        $jobsLinked = 0;
        $payoutsLinked = 0;
        $manualReview = [];

        $users = User::withTestData()->where('role', 'contractor')->get();
        foreach ($users as $user) {
            $exists = Contractor::withTestData()->where('user_id', $user->id)->exists();
            if ($exists) {
                continue;
            }
            if ($apply) {
                $this->completeness->ensureProfileForUser($user);
            }
            $created++;
        }

        $profileByUserId = Contractor::withTestData()->pluck('id', 'user_id');

        Job::withTestData()->whereNotNull('contractor_id')->orderBy('id')->chunkById(200, function ($jobs) use ($profileByUserId, $apply, &$jobsLinked, &$manualReview) {
            foreach ($jobs as $job) {
                $userId = (int) $job->contractor_id;
                $profileId = $profileByUserId[$userId] ?? null;
                if (! $profileId) {
                    $asProfile = Contractor::withTestData()->find($userId);
                    if ($asProfile) {
                        if ($apply) {
                            $job->forceFill([
                                'contractor_id' => $asProfile->user_id,
                                'contractor_profile_id' => $asProfile->id,
                            ])->save();
                        }
                        $jobsLinked++;
                        continue;
                    }
                    $manualReview[] = [
                        'type' => 'job',
                        'id' => $job->id,
                        'contractor_id' => $userId,
                        'reason' => 'no_matching_contractor_user_or_profile',
                    ];
                    continue;
                }
                if ((int) ($job->contractor_profile_id ?? 0) !== (int) $profileId) {
                    if ($apply) {
                        $job->forceFill(['contractor_profile_id' => $profileId])->save();
                    }
                    $jobsLinked++;
                }
            }
        });

        // payouts.contractor_id is overloaded: for payout_type/split_type pm|company it
        // stores the recipient user id (often a PM), not a contractor. Only contractor
        // splits should be matched against the contractors directory.
        Payout::withTestData()->whereNotNull('contractor_id')->orderBy('id')->chunkById(200, function ($payouts) use ($profileByUserId, $apply, &$payoutsLinked, &$manualReview) {
            foreach ($payouts as $payout) {
                $split = $payout->split_type ?: $payout->payout_type;
                if ($split !== 'contractor') {
                    continue;
                }
                $userId = (int) $payout->contractor_id;
                $profileId = $profileByUserId[$userId] ?? null;
                if (! $profileId) {
                    $manualReview[] = [
                        'type' => 'payout',
                        'id' => $payout->id,
                        'contractor_id' => $userId,
                        'payout_type' => $split,
                        'reason' => 'no_matching_contractor_user_or_profile',
                    ];
                    continue;
                }
                if ((int) ($payout->contractor_profile_id ?? 0) !== (int) $profileId) {
                    if ($apply) {
                        $payout->forceFill(['contractor_profile_id' => $profileId])->save();
                    }
                    $payoutsLinked++;
                }
            }
        });

        $result = [
            'profiles_created' => $created,
            'jobs_linked' => $jobsLinked,
            'payouts_linked' => $payoutsLinked,
            'manual_review' => $manualReview,
        ];

        Log::info('A-04 contractor directory sync', $result + ['apply' => $apply]);

        return $result;
    }

    /**
     * Invariant: every contractor user on an active job/payout has a directory profile.
     *
     * @return Collection<int, int> missing user ids
     */
    public function usersAssignedWithoutProfile(): Collection
    {
        $activeStatuses = ['cancelled', 'completed', 'paid_completed'];
        $jobUserIds = Job::productionOnly()
            ->whereNotNull('contractor_id')
            ->whereNotIn('status', $activeStatuses)
            ->pluck('contractor_id');
        $payoutUserIds = Payout::productionOnly()
            ->whereNotNull('contractor_id')
            ->where(function ($q) {
                $q->where('split_type', 'contractor')
                    ->orWhere(function ($inner) {
                        $inner->where(function ($s) {
                            $s->whereNull('split_type')->orWhere('split_type', '');
                        })->where('payout_type', 'contractor');
                    });
            })
            ->pluck('contractor_id');

        $userIds = $jobUserIds->merge($payoutUserIds)->unique()->filter()->values();
        $profileUserIds = Contractor::productionOnly()->whereIn('user_id', $userIds)->pluck('user_id');

        return $userIds->diff($profileUserIds)->values();
    }
}
