<?php

namespace App\Services\Contractors;

use App\Models\Contractor;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Audit A-04 / PM-06 — resolve contractor identity and gate new assignments.
 *
 * Operational job/payout FK remains users.id (auth). Profile FK is contractors.id.
 */
class ContractorAssignmentGuard
{
    public function __construct(
        private readonly ContractorProfileCompleteness $completeness,
        private readonly ContractorAvailabilityService $availability,
    ) {}

    /**
     * Resolve a contractor user + authoritative profile from an assignment payload.
     * Accepts either users.id or contractors.id (when the id matches a profile PK).
     *
     * @return array{user: User, profile: Contractor}
     */
    public function resolve(int $id): array
    {
        $user = User::withTestData()
            ->where('id', $id)
            ->where('role', 'contractor')
            ->first();

        if ($user) {
            $profile = $this->completeness->ensureProfileForUser($user);

            return ['user' => $user, 'profile' => $profile];
        }

        // Allow callers that pass contractors.id by mistake / intentionally
        $profile = Contractor::withTestData()->with('user')->find($id);
        if ($profile?->user && $profile->user->role === 'contractor') {
            return ['user' => $profile->user, 'profile' => $profile];
        }

        throw ValidationException::withMessages([
            'contractor_id' => ['No contractor found for the given id.'],
        ]);
    }

    /**
     * @return array{user: User, profile: Contractor}
     */
    public function assertAssignable(int $id): array
    {
        $resolved = $this->resolve($id);
        $profile = $resolved['profile'];
        $user = $resolved['user'];

        if ($user->status !== 'active') {
            throw ValidationException::withMessages([
                'contractor_id' => ['This contractor login is inactive and cannot be assigned.'],
            ]);
        }

        if ($profile->state === 'suspended') {
            throw ValidationException::withMessages([
                'contractor_id' => ['This contractor is suspended and cannot receive new assignments.'],
            ]);
        }

        if ($profile->state === 'deactivated') {
            throw ValidationException::withMessages([
                'contractor_id' => ['This contractor is deactivated and cannot receive new assignments.'],
            ]);
        }

        if (! $this->completeness->isCompliant($profile)) {
            $missing = $this->completeness->missingSteps($profile);
            $labels = array_column(
                array_filter($missing, fn ($s) => in_array($s['key'], ['wcb', 'liability_insurance'], true)),
                'label'
            );
            $detail = $labels !== []
                ? implode('; ', $labels)
                : 'WCB and/or liability insurance must be approved.';

            throw ValidationException::withMessages([
                'contractor_id' => ["This contractor is not compliant and cannot receive new assignments. {$detail}"],
            ]);
        }

        if ($profile->state !== 'approved') {
            $missing = $this->completeness->missingSteps($profile);
            $detail = $missing !== []
                ? ' Missing: '.implode('; ', array_column($missing, 'label')).'.'
                : '';

            throw ValidationException::withMessages([
                'contractor_id' => ["This contractor's profile is not approved for assignment.{$detail}"],
            ]);
        }

        return $resolved;
    }

    /**
     * CT-09 — gate NEW offers against availability preferences (does not touch accepted work).
     *
     * @return array{user: User, profile: Contractor}
     */
    public function assertAssignableForVisit(int $id, \Carbon\Carbon|string $visitAt): array
    {
        $resolved = $this->assertAssignable($id);
        if (! $this->availability->canReceiveNewOffer($resolved['profile'], $visitAt)) {
            throw ValidationException::withMessages([
                'contractor_id' => ['This contractor is unavailable for that date/time (paused, blackout, notice, or outside working hours). Existing accepted work is unaffected.'],
            ]);
        }

        return $resolved;
    }
}
