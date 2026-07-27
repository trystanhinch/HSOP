<?php

namespace App\Models;

use App\Models\Concerns\HasTestData;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayoutEvent extends Model
{
    use HasTestData;

    protected $fillable = [
        'payout_id',
        'job_id',
        'event_type',
        'from_status',
        'to_status',
        'amount',
        'actor_user_id',
        'snapshot',
        'notes',
        'is_test_data',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'snapshot' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function payout(): BelongsTo
    {
        return $this->belongsTo(Payout::class);
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }
}
