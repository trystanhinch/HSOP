<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payout extends Model
{
    protected $fillable = [
        'payout_type',
        'job_id',
        'contractor_id',
        'payout_amount',
        'status',
        'eligibility_status',
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
            'paid_date' => 'date',
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

    public function authorizedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'authorized_by');
    }
}
