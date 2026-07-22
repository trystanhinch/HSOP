<?php

namespace App\Models;

use App\Casts\DateOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payout extends Model
{
    protected $appends = ['amount'];

    protected $fillable = [
        'payout_type',
        'split_type',
        'job_id',
        'contractor_id',
        'pm_id',
        'payout_amount',
        'status',
        'eligibility_status',
        'eligible_at',
        'scheduled_for',
        'stripe_transfer_id',
        'paid_date',
        'authorized_by',
        'payout_method',
        'payout_due_date',
        'admin_notes',
    ];

    protected function casts(): array
    {
        return [
            'payout_amount' => 'decimal:2',
            'paid_date' => DateOnly::class,
            'scheduled_for' => DateOnly::class,
            'eligible_at' => 'datetime',
        ];
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }

    public function contractor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'contractor_id');
    }

    public function pm(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pm_id');
    }

    public function authorizedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'authorized_by');
    }

    public function getAmountAttribute(): float
    {
        return (float) $this->payout_amount;
    }
}
