<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * Milestone 6A.3 / Phase 5 — append-only evaluation run metadata.
 */
class AiEvaluationRun extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'provider',
        'model',
        'model_version',
        'prompt_version',
        'evaluation_version',
        'benchmark_set_version',
        'run_type',
        'initiated_by_type',
        'initiated_by_id',
        'actor_user_id',
        'personal_access_token_id',
        'started_at',
        'completed_at',
        'total_cost',
        'status',
        'trace_id',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'created_at' => 'datetime',
            'total_cost' => 'decimal:6',
            'initiated_by_id' => 'integer',
            'actor_user_id' => 'integer',
            'personal_access_token_id' => 'integer',
        ];
    }

    public function findings(): HasMany
    {
        return $this->hasMany(AiEvaluationFinding::class, 'evaluation_run_id');
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new LogicException('ai_evaluation_runs is append-only; updates are forbidden.');
        });

        static::deleting(function () {
            throw new LogicException('ai_evaluation_runs is append-only; deletes are forbidden.');
        });
    }
}
