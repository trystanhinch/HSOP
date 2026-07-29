<?php

namespace App\Services\Contractors;

use App\Models\AuditLog;
use App\Models\SiteVisit;
use App\Models\User;
use App\Services\SmsService;
use Carbon\Carbon;

/**
 * CT-07 — explicit assignment offer lifecycle.
 *
 * Offered → Viewed → Accepted/Confirmed | Declined | Expired | Reassigned
 * An offered assignment is NEVER treated as confirmed before acceptance.
 */
class ContractorAssignmentLifecycleService
{
    public const OFFERED = 'offered';

    public const VIEWED = 'viewed';

    public const ACCEPTED = 'accepted';

    public const DECLINED = 'declined';

    public const EXPIRED = 'expired';

    public const REASSIGNED = 'reassigned';

    public const CONFIRMED = 'confirmed';

    public function __construct(
        private SmsService $sms,
    ) {}

    public function responseDeadlineHours(): int
    {
        return max(1, (int) config('workflow.contractor_assignment.response_deadline_hours', 24));
    }

    public function defaultRespondBy(?Carbon $from = null): Carbon
    {
        return ($from ?? now())->copy()->addHours($this->responseDeadlineHours());
    }

    /**
     * Apply offer fields when creating/updating a site visit assignment.
     *
     * @return array<string, mixed>
     */
    public function offerAttributes(?int $previousContractorId = null): array
    {
        return [
            'status' => 'offered',
            'assignment_state' => self::OFFERED,
            'respond_by' => $this->defaultRespondBy(),
            'accepted_at' => null,
            'confirmed_at' => null,
            'declined_at' => null,
            'decline_reason' => null,
            'viewed_at' => null,
            'reassigned_at' => $previousContractorId ? now() : null,
            'previous_contractor_id' => $previousContractorId,
        ];
    }

    public function markViewed(SiteVisit $siteVisit): SiteVisit
    {
        $this->expireIfNeeded($siteVisit);
        $siteVisit->refresh();

        if (! in_array($siteVisit->assignment_state, [self::OFFERED, self::VIEWED], true)) {
            return $siteVisit;
        }

        if (! $siteVisit->viewed_at) {
            $siteVisit->update([
                'viewed_at' => now(),
                'assignment_state' => self::VIEWED,
            ]);
        }

        return $siteVisit->fresh();
    }

    public function accept(SiteVisit $siteVisit): SiteVisit
    {
        $this->expireIfNeeded($siteVisit);
        $siteVisit->refresh();

        if (in_array($siteVisit->assignment_state, [self::EXPIRED, self::DECLINED, self::REASSIGNED], true)) {
            abort(422, 'This assignment can no longer be accepted ('.$this->label($siteVisit->assignment_state).').');
        }
        if ($siteVisit->accepted_at || in_array($siteVisit->assignment_state, [self::ACCEPTED, self::CONFIRMED], true)) {
            abort(422, 'Already accepted');
        }

        $siteVisit->update([
            'status' => 'accepted',
            'assignment_state' => self::CONFIRMED,
            'accepted_at' => now(),
            'confirmed_at' => now(),
        ]);

        $this->audit($siteVisit, 'assignment_accepted');

        return $siteVisit->fresh();
    }

    public function decline(SiteVisit $siteVisit, User $actor, ?string $reason = null): SiteVisit
    {
        $this->expireIfNeeded($siteVisit);
        $siteVisit->refresh();

        if (in_array($siteVisit->assignment_state, [self::EXPIRED, self::DECLINED, self::REASSIGNED], true)) {
            abort(422, 'This assignment can no longer be declined.');
        }
        if ($siteVisit->accepted_at || in_array($siteVisit->assignment_state, [self::ACCEPTED, self::CONFIRMED], true)) {
            abort(422, 'Accepted assignments cannot be declined here — contact your PM.');
        }

        $siteVisit->update([
            'status' => 'declined',
            'assignment_state' => self::DECLINED,
            'declined_at' => now(),
            'decline_reason' => $reason ? mb_substr(trim($reason), 0, 500) : null,
        ]);

        $this->audit($siteVisit, 'assignment_declined', [
            'reason' => $siteVisit->decline_reason,
            'by' => $actor->id,
        ]);
        $this->alertPm($siteVisit, $actor, 'declined');

        return $siteVisit->fresh();
    }

    public function expireIfNeeded(SiteVisit $siteVisit): bool
    {
        if (! in_array($siteVisit->assignment_state, [self::OFFERED, self::VIEWED], true)) {
            return false;
        }
        if (! $siteVisit->respond_by || $siteVisit->respond_by->isFuture()) {
            return false;
        }
        if ($siteVisit->accepted_at) {
            return false;
        }

        $siteVisit->update([
            'status' => 'expired',
            'assignment_state' => self::EXPIRED,
        ]);
        $this->audit($siteVisit, 'assignment_expired');
        $this->alertPm($siteVisit, null, 'expired');

        return true;
    }

    /**
     * Display state for contractor/PM — never upgrades offered → confirmed.
     */
    public function effectiveState(SiteVisit $siteVisit): string
    {
        $this->expireIfNeeded($siteVisit);
        $siteVisit->refresh();

        $state = (string) ($siteVisit->assignment_state ?: self::OFFERED);
        // Legacy rows: accepted_at without assignment_state
        if ($siteVisit->accepted_at && ! in_array($state, [self::ACCEPTED, self::CONFIRMED], true)) {
            return self::CONFIRMED;
        }
        if ($siteVisit->declined_at && $state !== self::DECLINED) {
            return self::DECLINED;
        }

        return $state ?: self::OFFERED;
    }

    public function isConfirmed(SiteVisit $siteVisit): bool
    {
        return in_array($this->effectiveState($siteVisit), [self::ACCEPTED, self::CONFIRMED], true);
    }

    public function label(string $state): string
    {
        return match ($state) {
            self::OFFERED => 'Offered',
            self::VIEWED => 'Viewed',
            self::ACCEPTED => 'Accepted',
            self::CONFIRMED => 'Confirmed',
            self::DECLINED => 'Declined',
            self::EXPIRED => 'Expired',
            self::REASSIGNED => 'Reassigned',
            default => ucfirst(str_replace('_', ' ', $state)),
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function present(SiteVisit $siteVisit): array
    {
        $state = $this->effectiveState($siteVisit);

        return [
            'assignment_state' => $state,
            'assignment_state_label' => $this->label($state),
            'is_confirmed' => in_array($state, [self::ACCEPTED, self::CONFIRMED], true),
            'respond_by' => $siteVisit->respond_by?->toIso8601String(),
            'viewed_at' => $siteVisit->viewed_at?->toIso8601String(),
            'accepted_at' => $siteVisit->accepted_at?->toIso8601String(),
            'confirmed_at' => $siteVisit->confirmed_at?->toIso8601String(),
            'declined_at' => $siteVisit->declined_at?->toIso8601String(),
            'decline_reason' => $siteVisit->decline_reason,
            'response_deadline_hours' => $this->responseDeadlineHours(),
        ];
    }

    private function alertPm(SiteVisit $siteVisit, ?User $actor, string $event): void
    {
        $pm = User::find($siteVisit->pm_id);
        if (! $pm) {
            return;
        }
        $label = $siteVisit->lead?->address ?: 'site visit #'.$siteVisit->id;
        $who = $actor?->name ?: 'Contractor';
        $msg = $event === 'expired'
            ? "Site visit offer expired without response: {$label}."
            : "{$who} declined the site visit at {$label}."
                .($siteVisit->decline_reason ? ' Reason: '.$siteVisit->decline_reason : '');

        $this->sms->sendToUser($pm, $msg, 'site_visit_'.$event);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function audit(SiteVisit $siteVisit, string $action, array $meta = []): void
    {
        AuditLog::create([
            'user_id' => auth()->id(),
            'user_role' => auth()->user()?->role,
            'object_type' => 'site_visit',
            'object_id' => $siteVisit->id,
            'action_type' => $action,
            'new_value' => $meta ? json_encode($meta) : null,
        ]);
    }
}
