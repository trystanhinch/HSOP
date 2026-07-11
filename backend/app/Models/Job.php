<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Models\Concerns\HasActivityTimeline;
use App\Models\Concerns\HasNextAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Job extends Model
{
    use HasActivityTimeline, HasNextAction;
    protected $fillable = [
        'company_id',
        'lead_id',
        'customer_id',
        'contractor_id',
        'pm_id',
        'company_listing',
        'service_category',
        'address',
        'status',
        'job_title',
        'scope_of_work',
        'internal_notes',
        'contractor_submitted_price',
        'contractor_price_status',
        'contractor_price_submitted_at',
        'scheduled_start_date',
        'scheduled_start_time',
        'scheduled_end_date',
        'estimated_completion_date',
        'schedule_notes',
        'start_date',
        'end_date',
        'notes',
        'ready_for_review_at',
        'completed_at',
        'corrections_notes',
        'split_contractor_pct',
        'split_pm_pct',
        'split_company_pct',
        'pending_customer_approval_at',
        'customer_accepted_completion_at',
        'revision_description',
        'payment_method',
        'payment_confirmed_at',
        'payment_confirmed_by',
        'payment_reference',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => DateOnly::class,
            'end_date' => DateOnly::class,
            'scheduled_start_date' => DateOnly::class,
            'scheduled_end_date' => DateOnly::class,
            'estimated_completion_date' => DateOnly::class,
            'contractor_price_submitted_at' => 'datetime',
            'ready_for_review_at' => 'datetime',
            'completed_at' => 'datetime',
            'pending_customer_approval_at' => 'datetime',
            'customer_accepted_completion_at' => 'datetime',
            'payment_confirmed_at' => 'datetime',
            'contractor_submitted_price' => 'decimal:2',
            'split_contractor_pct' => 'decimal:2',
            'split_pm_pct' => 'decimal:2',
            'split_company_pct' => 'decimal:2',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function contractor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'contractor_id');
    }

    public function pm(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pm_id');
    }

    public function quote(): HasOne
    {
        return $this->hasOne(Quote::class);
    }

    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class);
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(Payout::class);
    }

    public function payout(): HasOne
    {
        return $this->hasOne(Payout::class)->where('payout_type', 'contractor');
    }

    public function pmPayout(): HasOne
    {
        return $this->hasOne(Payout::class)->where('payout_type', 'pm');
    }

    public function revisionRequests(): HasMany
    {
        return $this->hasMany(RevisionRequest::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function updates(): HasMany
    {
        return $this->hasMany(JobUpdate::class);
    }

    public function files(): HasMany
    {
        return $this->hasMany(File::class, 'related_id')->where('related_type', 'job');
    }
}
