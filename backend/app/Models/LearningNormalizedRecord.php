<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * Milestone 6B Phase 4 — Learning AI normalized record drafts (append-only).
 * Eligibility here is pending_review|provisional only — never verified/excluded.
 */
class LearningNormalizedRecord extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'subject_type',
        'subject_id',
        'job_id',
        'estimate_outcome_id',
        'lead_id',
        'learning_eligibility_status',
        'extracted_fields',
        'provenance',
        'confidence',
        'warnings',
        'missing_data_flags',
        'notes',
        'created_by',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'extracted_fields' => 'array',
            'provenance' => 'array',
            'warnings' => 'array',
            'missing_data_flags' => 'array',
            'confidence' => 'float',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new LogicException('learning_normalized_records are append-only.');
        });
        static::deleting(function () {
            throw new LogicException('learning_normalized_records are append-only.');
        });
    }

    public function evidenceEntries(): HasMany
    {
        return $this->hasMany(LearningEvidenceEntry::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
