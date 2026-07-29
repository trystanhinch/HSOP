<?php

namespace App\Services\Leads;

use App\Models\Lead;
use App\Models\User;
use App\Services\Customers\CustomerValidationService;
use App\Services\LeadIntake\LeadIntakeQuarantineEvaluator;

/**
 * A-35 — Block convert until identity/contact fields validate, or owner override is recorded.
 */
class LeadConvertGate
{
    public function __construct(
        private CustomerValidationService $validation,
        private LeadIntakeQuarantineEvaluator $evaluator,
    ) {}

    /**
     * @return array{ok: bool, errors: list<string>, contact_clickable: bool}
     */
    public function evaluate(Lead $lead): array
    {
        $errors = [];

        if (! $lead->contact_name || ! $this->evaluator->isAcceptableName($lead->contact_name)) {
            $errors[] = 'Contact name is missing or invalid';
        }

        $phoneOk = $this->validation->isValidPhone($lead->phone) || $this->evaluator->isValidPhone($lead->phone);
        $emailOk = $this->validation->isValidEmail($lead->email);

        if (! $phoneOk && ! $emailOk) {
            $errors[] = 'A valid phone or email is required';
        }

        if ($lead->needs_manual_review) {
            $errors[] = 'Lead still needs manual review';
        }

        if ($lead->ignored_at || $lead->merged_into_lead_id) {
            $errors[] = 'Lead is ignored or already merged';
        }

        $contactClickable = ($phoneOk || $emailOk)
            && ! $lead->needs_manual_review
            && ($lead->contact_validated_at !== null || (! $lead->needs_manual_review && $errors === []));

        // Clickable when validated/reviewed: not in needs_manual_review and has valid contact
        $contactClickable = ! $lead->needs_manual_review && ($phoneOk || $emailOk) && $lead->ignored_at === null;

        return [
            'ok' => $errors === [],
            'errors' => $errors,
            'contact_clickable' => $contactClickable,
        ];
    }

    public function assertCanConvert(Lead $lead, User $actor, bool $ownerOverride = false, ?string $overrideReason = null): void
    {
        $result = $this->evaluate($lead);
        if ($result['ok']) {
            if (! $lead->contact_validated_at) {
                $lead->update(['contact_validated_at' => now()]);
            }

            return;
        }

        if ($ownerOverride && $actor->role === 'owner') {
            if ($overrideReason === null || trim($overrideReason) === '') {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'owner_override_reason' => ['Owner override requires a reason'],
                ]);
            }
            $lead->update([
                'convert_override_by' => $actor->id,
                'convert_override_at' => now(),
                'convert_override_reason' => $overrideReason,
                'needs_manual_review' => false,
                'contact_validated_at' => $lead->contact_validated_at ?: now(),
            ]);

            return;
        }

        throw \Illuminate\Validation\ValidationException::withMessages([
            'convert' => $result['errors'],
        ]);
    }
}
