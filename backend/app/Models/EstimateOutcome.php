<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EstimateOutcome extends Model
{
    protected $fillable = [
        'estimate_group_id',
        'lead_id',
        'job_id',
        'brand_id',
        'version',
        'source_kind',
        'service_category',
        'price_low',
        'price_high',
        'currency',
        'confidence',
        'available',
        'widened',
        'is_placeholder',
        'is_current',
        'pricing_rule_id',
        'inputs_used',
        'calculation',
        'materials_assumptions',
        'labour_assumptions',
        'reasoning_snapshot',
        'ai_provider',
        'ai_model',
        'ai_model_version',
        'estimator_engine',
        'estimated_at',
        'actor_id',
        'supersedes_id',
        'reason',
        'embedding_vector',
        // Reserved for future weather/env data — intentionally left null (no weather API yet).
        'environmental_context',
        'learning_eligibility_status',
        'learning_eligibility_reason',
        'learning_eligibility_reviewed_by',
        'learning_eligibility_reviewed_at',
        'learning_recommended_status',
        'learning_recommended_by',
        'learning_recommended_at',
        'learning_recommendation_reason',
        'learning_recommendation_missing_actuals',
        'learning_approved_by',
        'learning_approved_at',
    ];

    protected function casts(): array
    {
        return [
            'price_low' => 'float',
            'price_high' => 'float',
            'available' => 'boolean',
            'widened' => 'boolean',
            'is_placeholder' => 'boolean',
            'is_current' => 'boolean',
            'inputs_used' => 'array',
            'calculation' => 'array',
            'materials_assumptions' => 'array',
            'labour_assumptions' => 'array',
            'reasoning_snapshot' => 'array',
            'embedding_vector' => 'array',
            'environmental_context' => 'array',
            'estimated_at' => 'datetime',
            'learning_eligibility_reviewed_at' => 'datetime',
            'learning_recommended_at' => 'datetime',
            'learning_recommendation_missing_actuals' => 'array',
            'learning_approved_at' => 'datetime',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function learningEligibilityReviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'learning_eligibility_reviewed_by');
    }

    public function learningRecommendedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'learning_recommended_by');
    }

    public function learningApprovedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'learning_approved_by');
    }

    /**
     * Production learning corpus — Verified only. Recommendations never qualify.
     */
    public function scopeProductionLearningSet($query)
    {
        return $query->where('learning_eligibility_status', 'verified');
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }

    public function successors(): HasMany
    {
        return $this->hasMany(self::class, 'supersedes_id');
    }
}
