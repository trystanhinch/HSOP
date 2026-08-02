<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Milestone 6B Phase 4 — append-only evidence attachments on a normalized learning record.
 */
class LearningEvidenceEntry extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'learning_normalized_record_id',
        'confidence',
        'source_references',
        'warnings',
        'missing_data_flags',
        'notes',
        'created_by',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'source_references' => 'array',
            'warnings' => 'array',
            'missing_data_flags' => 'array',
            'confidence' => 'float',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new LogicException('learning_evidence_entries are append-only.');
        });
        static::deleting(function () {
            throw new LogicException('learning_evidence_entries are append-only.');
        });
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(LearningNormalizedRecord::class, 'learning_normalized_record_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
