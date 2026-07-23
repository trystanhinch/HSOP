<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Booking extends Model
{
    protected $fillable = [
        'brand_id',
        'lead_id',
        'job_id',
        'intake_session_id',
        'booking_hold_id',
        'site_visit_id',
        'resource_key',
        'pm_id',
        'contractor_id',
        'service_category',
        'slot_start',
        'slot_end',
        'timezone',
        'status',
        'confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'slot_start' => 'datetime',
            'slot_end' => 'datetime',
            'confirmed_at' => 'datetime',
        ];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function hold(): BelongsTo
    {
        return $this->belongsTo(BookingHold::class, 'booking_hold_id');
    }

    public function siteVisit(): BelongsTo
    {
        return $this->belongsTo(SiteVisit::class);
    }

    public function pm(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pm_id');
    }

    public function contractor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'contractor_id');
    }
}
