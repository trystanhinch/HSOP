<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Milestone 6B Phase 5 — canonical assembled learning record (derived, versioned).
 * Source tables remain authoritative. Distinct from Phase 4 LearningNormalizedRecord drafts.
 */
class LearningRecord extends Model
{
    protected $fillable = [
        'record_group_id',
        'version',
        'is_current',
        'job_id',
        'lead_id',
        'property_id',
        'region_id',
        'customer_id',
        'contractor_id',
        'pm_id',
        'quote_id',
        'invoice_id',
        'current_estimate_outcome_id',
        'eligibility_source_type',
        'eligibility_source_id',
        'eligibility_status_snapshot',
        'payload',
        'provenance',
        'links',
        'missing_sources',
        'assembled_at',
    ];

    protected function casts(): array
    {
        return [
            'is_current' => 'boolean',
            'version' => 'integer',
            'payload' => 'array',
            'provenance' => 'array',
            'links' => 'array',
            'missing_sources' => 'array',
            'assembled_at' => 'datetime',
        ];
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    public function currentEstimateOutcome(): BelongsTo
    {
        return $this->belongsTo(EstimateOutcome::class, 'current_estimate_outcome_id');
    }

    /**
     * Live Phase 3 eligibility from the source job/estimate — not an independent field.
     */
    public function resolvedEligibilityStatus(): ?string
    {
        if ($this->eligibility_source_type === 'estimate_outcome') {
            $status = EstimateOutcome::query()
                ->where('id', $this->eligibility_source_id)
                ->value('learning_eligibility_status');
            if ($status) {
                return $status;
            }
        }

        if ($this->eligibility_source_type === 'job' || $this->job_id) {
            return Job::query()
                ->where('id', $this->eligibility_source_type === 'job' ? $this->eligibility_source_id : $this->job_id)
                ->value('learning_eligibility_status');
        }

        return $this->eligibility_status_snapshot;
    }

    public function scopeCurrent($query)
    {
        return $query->where('is_current', true);
    }

    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'record_group_id' => $this->record_group_id,
            'version' => $this->version,
            'is_current' => (bool) $this->is_current,
            'job_id' => $this->job_id,
            'lead_id' => $this->lead_id,
            'property_id' => $this->property_id,
            'region_id' => $this->region_id,
            'eligibility_source_type' => $this->eligibility_source_type,
            'eligibility_source_id' => $this->eligibility_source_id,
            'eligibility_status_snapshot' => $this->eligibility_status_snapshot,
            'eligibility_status' => $this->resolvedEligibilityStatus(),
            'payload' => $this->payload,
            'provenance' => $this->provenance,
            'links' => $this->links,
            'missing_sources' => $this->missing_sources,
            'assembled_at' => optional($this->assembled_at)?->toIso8601String(),
        ];
    }
}
