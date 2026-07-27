<?php

namespace App\Services\Contractors;

use App\Models\Contractor;
use App\Models\User;

/**
 * Audit A-04 / PM-06 — compute missing profile steps and derive state.
 */
class ContractorProfileCompleteness
{
    public const STATES = [
        'invited',
        'profile_incomplete',
        'approved',
        'suspended',
        'deactivated',
    ];

    /**
     * @return list<array{key: string, label: string}>
     */
    public function missingSteps(Contractor $contractor): array
    {
        $steps = [];

        $displayName = trim((string) ($contractor->legal_name ?: $contractor->operating_name ?: ''));
        if ($displayName === '') {
            $steps[] = ['key' => 'legal_name', 'label' => 'Add legal or operating name'];
        }

        $phone = trim((string) ($contractor->phone ?: $contractor->user?->phone ?: ''));
        if ($phone === '') {
            $steps[] = ['key' => 'phone', 'label' => 'Add a phone number'];
        }

        $email = trim((string) ($contractor->email ?: $contractor->user?->email ?: ''));
        if ($email === '') {
            $steps[] = ['key' => 'email', 'label' => 'Add an email address'];
        }

        $services = array_values(array_filter($contractor->services ?? [], fn ($s) => trim((string) $s) !== ''));
        if ($services === []) {
            $steps[] = ['key' => 'services', 'label' => 'Add at least one service offered'];
        }

        if (! $this->isComplianceOk($contractor->wcb_status)) {
            $steps[] = ['key' => 'wcb', 'label' => 'Upload and get WCB coverage approved'];
        }

        if (! $this->isComplianceOk($contractor->liability_insurance_status)) {
            $steps[] = ['key' => 'liability_insurance', 'label' => 'Upload and get liability insurance approved'];
        }

        return $steps;
    }

    public function isComplianceOk(?string $status): bool
    {
        return in_array(strtolower((string) $status), ['approved', 'verified', 'valid'], true);
    }

    public function isCompliant(Contractor $contractor): bool
    {
        return $this->isComplianceOk($contractor->wcb_status)
            && $this->isComplianceOk($contractor->liability_insurance_status);
    }

    /**
     * Derive state unless manually locked to suspended/deactivated.
     */
    public function deriveState(Contractor $contractor): string
    {
        $current = (string) ($contractor->state ?? '');
        if (in_array($current, ['suspended', 'deactivated'], true)) {
            return $current;
        }

        $missing = $this->missingSteps($contractor);
        if ($missing !== []) {
            // Invited = login exists, nothing filled beyond name from user create
            $hasAnyProfileData = filled($contractor->legal_name)
                || filled($contractor->operating_name)
                || ! empty($contractor->services)
                || $this->isComplianceOk($contractor->wcb_status)
                || $this->isComplianceOk($contractor->liability_insurance_status);

            return $hasAnyProfileData ? 'profile_incomplete' : 'invited';
        }

        return 'approved';
    }

    /**
     * Keep legacy approval_status in sync for existing matchers/UI.
     */
    public function syncApprovalStatus(string $state): string
    {
        return match ($state) {
            'approved' => 'approved',
            'suspended', 'deactivated' => 'suspended',
            default => 'pending',
        };
    }

    public function refresh(Contractor $contractor, bool $save = true): Contractor
    {
        if (! $contractor->relationLoaded('user')) {
            $contractor->load('user:id,name,email,phone,status');
        }

        $state = $this->deriveState($contractor);
        $contractor->state = $state;
        $contractor->approval_status = $this->syncApprovalStatus($state);

        if ($save) {
            $contractor->save();
        }

        return $contractor;
    }

    /**
     * Ensure a contractors row exists for a contractor user.
     */
    public function ensureProfileForUser(User $user, string $defaultState = 'profile_incomplete'): Contractor
    {
        $profile = Contractor::withTestData()->where('user_id', $user->id)->first();
        if ($profile) {
            return $profile;
        }

        $profile = Contractor::withTestData()->create([
            'user_id' => $user->id,
            'legal_name' => $user->name,
            'operating_name' => $user->name,
            'contact_name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'services' => [],
            'cities' => [],
            'wcb_status' => 'not_uploaded',
            'liability_insurance_status' => 'not_uploaded',
            'approval_status' => 'pending',
            'state' => $defaultState,
            'is_test_data' => (bool) ($user->is_test_data ?? false),
        ]);

        return $this->refresh($profile);
    }

    /**
     * Plain-language operational warnings for PM view (no Stripe/banking details).
     *
     * @return list<string>
     */
    public function operationalWarnings(Contractor $contractor): array
    {
        $warnings = [];
        if (in_array($contractor->state, ['suspended', 'deactivated'], true)) {
            $warnings[] = $contractor->state === 'suspended'
                ? 'This contractor is suspended and cannot receive new assignments.'
                : 'This contractor is deactivated and cannot receive new assignments.';
        }

        if (! $this->isComplianceOk($contractor->wcb_status)) {
            $warnings[] = 'WCB coverage is missing or not approved.';
        }
        if (! $this->isComplianceOk($contractor->liability_insurance_status)) {
            $warnings[] = 'Liability insurance is missing or not approved.';
        }

        $missing = $this->missingSteps($contractor);
        if ($missing !== [] && ! in_array($contractor->state, ['suspended', 'deactivated'], true)) {
            $warnings[] = 'Profile incomplete: '.implode('; ', array_column($missing, 'label')).'.';
        }

        return $warnings;
    }
}
