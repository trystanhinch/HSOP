<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Models\Concerns\HasActivityTimeline;
use App\Models\Concerns\HasNextAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Lead extends Model
{
    use HasActivityTimeline, HasNextAction;
    protected $fillable = [
        'company_id',
        'company_source_id',
        'brand_id',
        'customer_id',
        'contact_name',
        'phone',
        'email',
        'address',
        'service_category',
        'source',
        'intake_channel',
        'conversation_id',
        'company_listing',
        'notes',
        'raw_email_copy',
        'parse_metadata',
        'needs_manual_review',
        'project_description',
        'internal_notes',
        'assigned_pm_id',
        'assigned_contractor_id',
        'status',
        'site_visit_date',
        'site_visit_time',
        'site_visit_contractor_id',
        'site_visit_notes',
        'customer_portal_token',
        'contractor_price',
        'contractor_price_submitted_at',
        'contractor_price_notes',
        'price_estimate_low',
        'price_estimate_high',
        'price_estimate_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'site_visit_date' => DateOnly::class,
            'contractor_price_submitted_at' => 'datetime',
            'parse_metadata' => 'array',
            'price_estimate_snapshot' => 'array',
            'needs_manual_review' => 'boolean',
            'price_estimate_low' => 'float',
            'price_estimate_high' => 'float',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function companySource(): BelongsTo
    {
        return $this->belongsTo(CompanySource::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function assignedPm(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_pm_id');
    }

    public function assignedContractor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_contractor_id');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(LeadPhoto::class);
    }

    public function estimateOutcomes(): HasMany
    {
        return $this->hasMany(EstimateOutcome::class);
    }

    public function currentEstimateOutcome(): HasOne
    {
        return $this->hasOne(EstimateOutcome::class)->where('is_current', true);
    }

    public function job(): HasOne
    {
        return $this->hasOne(Job::class);
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class);
    }

    public function siteVisitContractor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'site_visit_contractor_id');
    }

    public function siteVisit(): HasOne
    {
        return $this->hasOne(SiteVisit::class);
    }

    public function intakeSession(): BelongsTo
    {
        return $this->belongsTo(IntakeSession::class, 'conversation_id');
    }
}
