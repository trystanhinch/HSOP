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
        'customer_id',
        'contact_name',
        'phone',
        'email',
        'address',
        'service_category',
        'source',
        'company_listing',
        'notes',
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
    ];

    protected function casts(): array
    {
        return [
            'site_visit_date' => DateOnly::class,
            'contractor_price_submitted_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
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
}
