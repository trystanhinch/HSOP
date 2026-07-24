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

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }

    public function successors(): HasMany
    {
        return $this->hasMany(self::class, 'supersedes_id');
    }
}
