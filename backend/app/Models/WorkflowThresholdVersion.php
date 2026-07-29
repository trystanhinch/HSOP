<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowThresholdVersion extends Model
{
    protected $fillable = [
        'actor_id',
        'thresholds',
        'preview_timeline',
        'clock_mode',
        'business_hours_profile_id',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'thresholds' => 'array',
            'preview_timeline' => 'array',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
