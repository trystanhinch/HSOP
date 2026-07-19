<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowReview extends Model
{
    protected $table = 'workflow_reviews';

    protected $fillable = [
        'job_id',
        'status',
        'internal_rating',
        'internal_notes',
        'google_link_shown',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'google_link_shown' => 'boolean',
            'resolved_at' => 'datetime',
        ];
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }
}
