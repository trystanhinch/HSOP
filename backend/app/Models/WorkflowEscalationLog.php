<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowEscalationLog extends Model
{
    protected $fillable = [
        'next_action_id',
        'rule_key',
        'stage',
        'fired_at',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'fired_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function nextAction(): BelongsTo
    {
        return $this->belongsTo(NextAction::class);
    }
}
