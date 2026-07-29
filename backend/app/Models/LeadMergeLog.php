<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadMergeLog extends Model
{
    protected $fillable = [
        'primary_lead_id',
        'merged_lead_ids',
        'actor_id',
        'pre_merge_snapshot',
        'field_choices',
        'reassignment_counts',
        'status',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'merged_lead_ids' => 'array',
            'pre_merge_snapshot' => 'array',
            'field_choices' => 'array',
            'reassignment_counts' => 'array',
        ];
    }

    public function primaryLead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'primary_lead_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
