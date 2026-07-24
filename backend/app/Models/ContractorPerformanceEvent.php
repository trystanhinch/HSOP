<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContractorPerformanceEvent extends Model
{
    public const TYPES = [
        'response_time',
        'schedule_adherence',
        'callback',
        'customer_rating',
        'revision_requested',
        'labour_variance',
        'materials_variance',
        'profitability',
        'completion_time',
    ];

    protected $fillable = [
        'contractor_id',
        'job_id',
        'lead_id',
        'event_type',
        'event_data',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'event_data' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function contractor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'contractor_id');
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}
