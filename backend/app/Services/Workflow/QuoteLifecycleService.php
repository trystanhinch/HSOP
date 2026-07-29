<?php

namespace App\Services\Workflow;

use App\Models\NextAction;
use App\Models\Quote;
use App\Models\QuoteItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A-32 — Quote lifecycle vs follow-up task.
 *
 * Lifecycle lives on quotes.status.
 * Follow-up is a NextAction (escalation_rule=quote_follow_up) — NEVER a status value.
 * Sent versions become immutable; revisions are new rows with incremented revision_number.
 */
class QuoteLifecycleService
{
    /** Follow-up is a task, not a status (legacy `follow_up` rows are normalized on migrate). */
    public const FOLLOW_UP_RULE = 'quote_follow_up';

    public const LIFECYCLE_STATUSES = [
        'pricing_requested',
        'pricing_received',
        'draft',
        'internal_review',
        'sent',
        'viewed',
        'revision_requested',
        'approved',
        'declined',
        'expired',
    ];

    public const LEGACY_STATUSES = [
        'follow_up',
        'rejected',
        'revised',
    ];

    public const TERMINAL_STATUSES = [
        'approved',
        'declined',
        'expired',
        'revision_requested',
    ];

    public const CUSTOMER_ACTIONABLE = [
        'sent',
        'viewed',
        'follow_up', // legacy until backfill
    ];

    public const EDITABLE = [
        'draft',
        'internal_review',
        'revised', // legacy editable
    ];

    /**
     * Allowed transitions (from → to[]).
     *
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        'pricing_requested' => ['pricing_received', 'draft', 'declined', 'expired'],
        'pricing_received' => ['draft', 'internal_review', 'declined', 'expired'],
        'draft' => ['internal_review', 'sent', 'declined', 'expired'],
        'internal_review' => ['draft', 'sent', 'declined', 'expired'],
        'sent' => ['viewed', 'approved', 'declined', 'expired', 'revision_requested'],
        'viewed' => ['approved', 'declined', 'expired', 'revision_requested'],
        'follow_up' => ['viewed', 'approved', 'declined', 'expired', 'revision_requested'], // legacy
        'revision_requested' => [], // terminal for that version; new revision is a new row
        'approved' => [],
        'declined' => [],
        'expired' => [],
        'rejected' => [], // legacy terminal
        'revised' => ['draft', 'internal_review', 'sent'], // legacy
    ];

    public function normalizeStatus(?string $status): string
    {
        $status = strtolower((string) $status);
        if ($status === 'rejected') {
            return 'declined';
        }
        if ($status === 'follow_up') {
            return 'viewed';
        }
        if ($status === 'revised') {
            return 'draft';
        }

        return $status;
    }

    public function canTransition(?string $from, string $to): bool
    {
        $from = $this->normalizeStatus($from ?: 'draft');
        $to = $this->normalizeStatus($to);
        if ($from === $to) {
            return true;
        }
        $allowed = self::TRANSITIONS[$from] ?? [];

        return in_array($to, $allowed, true);
    }

    public function transition(Quote $quote, string $to, array $extraAttributes = [], ?string $reason = null): Quote
    {
        $from = $quote->status;
        $to = $this->normalizeStatus($to);

        if (! $this->canTransition($from, $to)) {
            throw ValidationException::withMessages([
                'status' => ["Cannot transition quote from {$from} to {$to}"],
            ]);
        }

        if ($quote->is_immutable && ! in_array($to, self::TERMINAL_STATUSES, true) && $to !== 'viewed') {
            // Immutable sent versions may only move to viewed (first open) or terminal outcomes.
            if (! ($from === 'sent' && $to === 'viewed')) {
                throw ValidationException::withMessages([
                    'status' => ['This quote version is immutable'],
                ]);
            }
        }

        $attrs = array_merge(['status' => $to], $extraAttributes);
        $quote->update($attrs);

        if (in_array($to, self::TERMINAL_STATUSES, true)) {
            $this->stopFollowUp($quote->fresh(), $reason ?? $to);
        }

        Log::info('quote_lifecycle_transition', [
            'quote_id' => $quote->id,
            'from' => $from,
            'to' => $to,
            'reason' => $reason,
        ]);

        return $quote->fresh();
    }

    public function markSent(Quote $quote, ?string $token = null): Quote
    {
        $token = $token ?: ($quote->customer_token ?: Str::random(64));
        $attrs = [
            'customer_token' => $token,
            'sent_at' => $quote->sent_at ?: now(),
            'is_immutable' => true,
            'follow_up_stopped_at' => null,
        ];

        return $this->transition($quote, 'sent', $attrs, 'send');
    }

    public function markViewed(Quote $quote): Quote
    {
        if ($quote->status !== 'sent') {
            return $quote;
        }

        return $this->transition($quote, 'viewed', [
            'viewed_at' => $quote->viewed_at ?: now(),
        ], 'view');
    }

    public function markInternalReview(Quote $quote): Quote
    {
        return $this->transition($quote, 'internal_review', [], 'internal_review');
    }

    public function approve(Quote $quote): Quote
    {
        if (! in_array($quote->status, self::CUSTOMER_ACTIONABLE, true)) {
            throw ValidationException::withMessages([
                'status' => ['Quote cannot be approved in current status'],
            ]);
        }

        return $this->transition($quote, 'approved', [
            'accepted_at' => now(),
            'is_immutable' => true,
        ], 'approved');
    }

    public function decline(Quote $quote, ?string $reason = null): Quote
    {
        $attrs = [
            'declined_at' => now(),
            'is_immutable' => true,
        ];
        if ($reason !== null && $reason !== '') {
            $attrs['rejection_reason'] = $reason;
        }

        // Allow decline from actionable + draft/internal_review (staff mark declined)
        $from = $this->normalizeStatus($quote->status);
        if (! $this->canTransition($from, 'declined')) {
            throw ValidationException::withMessages([
                'status' => ['Quote cannot be declined in current status'],
            ]);
        }

        return $this->transition($quote, 'declined', $attrs, 'declined');
    }

    public function expire(Quote $quote): Quote
    {
        return $this->transition($quote, 'expired', [
            'expired_at' => now(),
            'is_immutable' => true,
        ], 'expired');
    }

    /**
     * Create a new draft revision. Original sent version stays immutable and unchanged
     * except status → revision_requested (and follow-up stopped).
     */
    public function createRevision(Quote $quote): Quote
    {
        if (! in_array($quote->status, ['sent', 'viewed', 'follow_up', 'approved', 'declined', 'expired'], true)
            && ! $quote->is_immutable) {
            throw ValidationException::withMessages([
                'status' => ['Only a sent (or later) quote can be revised'],
            ]);
        }

        return DB::transaction(function () use ($quote) {
            $quote->loadMissing('items');
            $rootId = $quote->root_quote_id ?: $quote->id;
            $maxRev = (int) Quote::query()
                ->where(function ($q) use ($rootId, $quote) {
                    $q->where('root_quote_id', $rootId)->orWhere('id', $rootId)->orWhere('parent_quote_id', $quote->id);
                })
                ->max('revision_number');
            $nextRev = max($maxRev, (int) $quote->revision_number) + 1;

            // Freeze original without mutating pricing fields
            if (! in_array($quote->status, ['approved', 'declined', 'expired'], true)) {
                $this->transition($quote, 'revision_requested', [
                    'is_immutable' => true,
                ], 'revision');
            } else {
                $this->stopFollowUp($quote, 'revision');
                $quote->update(['is_immutable' => true]);
            }

            $snapshot = $quote->only([
                'lead_id', 'company_id', 'job_id', 'customer_id', 'scope_of_work',
                'contractor_base_price', 'customer_price_before_gst', 'hsop_markup',
                'gst_enabled', 'subtotal', 'gst_rate', 'gst', 'customer_total',
                'internal_notes', 'customer_notes', 'contractor_pct', 'pm_pct', 'company_pct',
                'pm_amount', 'company_amount', 'brand_name_snapshot',
            ]);

            $revision = Quote::createWithUniqueQuoteNumber(array_merge($snapshot, [
                'status' => 'draft',
                'revision_number' => $nextRev,
                'parent_quote_id' => $quote->id,
                'root_quote_id' => $rootId,
                'is_immutable' => false,
                'customer_token' => null,
                'sent_at' => null,
                'viewed_at' => null,
                'accepted_at' => null,
                'declined_at' => null,
                'expired_at' => null,
                'follow_up_due_at' => null,
                'follow_up_stopped_at' => null,
                'rejection_reason' => null,
                'pdf_ref' => null,
            ]));

            foreach ($quote->items as $i => $item) {
                QuoteItem::create([
                    'quote_id' => $revision->id,
                    'description' => $item->description,
                    'quantity' => $item->quantity,
                    'unit' => $item->unit,
                    'unit_price' => $item->unit_price,
                    'total' => $item->total,
                    'sort_order' => $item->sort_order ?? $i,
                ]);
            }

            return $revision->fresh(['items']);
        });
    }

    public function flagFollowUpDue(Quote $quote, NextAction $action): Quote
    {
        // Do NOT change quote status — follow-up is a separate task.
        if (! in_array($quote->status, ['sent', 'viewed'], true)) {
            return $quote;
        }

        $quote->update([
            'follow_up_due_at' => $quote->follow_up_due_at ?: now(),
            'follow_up_stopped_at' => null,
        ]);

        return $quote->fresh();
    }

    public function stopFollowUp(Quote $quote, ?string $reason = null): void
    {
        $job = $quote->job;
        if ($job) {
            NextAction::query()
                ->where('subject_type', $job->getMorphClass())
                ->where('subject_id', $job->id)
                ->where('escalation_rule', self::FOLLOW_UP_RULE)
                ->whereIn('status', ['pending', 'overdue', 'escalated'])
                ->update([
                    'status' => 'completed',
                    'last_action_at' => now(),
                ]);
        }

        // Also cancel by description match if rule missing on older rows
        if ($job) {
            NextAction::query()
                ->where('subject_type', $job->getMorphClass())
                ->where('subject_id', $job->id)
                ->whereIn('status', ['pending', 'overdue', 'escalated'])
                ->where('action_description', 'like', '%quote #'.$quote->id.'%')
                ->update([
                    'status' => 'completed',
                    'last_action_at' => now(),
                ]);
        }

        if ($quote->exists) {
            $quote->update([
                'follow_up_stopped_at' => now(),
            ]);
        }

        Log::info('quote_follow_up_stopped', [
            'quote_id' => $quote->id,
            'reason' => $reason,
        ]);
    }

    public function hasOpenFollowUp(Quote $quote): bool
    {
        if ($quote->follow_up_stopped_at) {
            return false;
        }
        if (in_array($quote->status, self::TERMINAL_STATUSES, true)) {
            return false;
        }
        $job = $quote->job;
        if (! $job) {
            return (bool) $quote->follow_up_due_at;
        }

        return NextAction::query()
            ->where('subject_type', $job->getMorphClass())
            ->where('subject_id', $job->id)
            ->where('escalation_rule', self::FOLLOW_UP_RULE)
            ->whereIn('status', ['pending', 'overdue', 'escalated'])
            ->exists();
    }

    /**
     * Expand list filter status buckets (mirrors JobLifecycleService::expandFilterStatus).
     *
     * @return list<string>|null
     */
    public function expandFilterStatus(?string $status): ?array
    {
        if (! $status) {
            return null;
        }
        if ($status === 'follow_up_due' || $status === 'follow_up') {
            return ['sent', 'viewed']; // further filtered by open NextAction in controller
        }
        if ($status === 'declined') {
            return ['declined', 'rejected'];
        }
        if ($status === 'draft') {
            return ['draft', 'revised'];
        }

        return [$status];
    }
}
