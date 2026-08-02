<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Models\Concerns\HasActivityTimeline;
use App\Models\Concerns\HasNextAction;
use App\Models\Concerns\HasTestData;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Lead extends Model
{
    use HasActivityTimeline, HasNextAction, HasTestData;
    protected $fillable = [
        'company_id',
        'company_source_id',
        'brand_id',
        'customer_id',
        'contact_name',
        'phone',
        'email',
        'address',
        'property_id',
        'service_category',
        'source',
        'intake_channel',
        'conversation_id',
        'company_listing',
        'notes',
        'raw_email_copy',
        'parse_metadata',
        'needs_manual_review',
        'duplicate_group_id',
        'is_duplicate_primary',
        'merged_into_lead_id',
        'merged_at',
        'ignored_at',
        'ignore_reason',
        'review_reason',
        'convert_override_by',
        'convert_override_at',
        'convert_override_reason',
        'contact_validated_at',
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
            'is_duplicate_primary' => 'boolean',
            'merged_at' => 'datetime',
            'ignored_at' => 'datetime',
            'convert_override_at' => 'datetime',
            'contact_validated_at' => 'datetime',
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

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
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

    public function booking(): HasOne
    {
        return $this->hasOne(Booking::class)->latestOfMany();
    }

    public function intakeSession(): BelongsTo
    {
        return $this->belongsTo(IntakeSession::class, 'conversation_id');
    }
}
