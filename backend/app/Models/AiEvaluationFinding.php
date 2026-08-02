<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Milestone 6A.3 / Phase 5 — append-only evaluation finding.
 */
class AiEvaluationFinding extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'evaluation_run_id',
        'subject_type',
        'subject_id',
        'dimension',
        'score',
        'max_score',
        'critique',
        'statement_kind',
        'evidence_reference',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'evaluation_run_id' => 'integer',
            'subject_id' => 'integer',
            'score' => 'decimal:2',
            'max_score' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(AiEvaluationRun::class, 'evaluation_run_id');
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new LogicException('ai_evaluation_findings is append-only; updates are forbidden.');
        });

        static::deleting(function () {
            throw new LogicException('ai_evaluation_findings is append-only; deletes are forbidden.');
        });
    }
}
