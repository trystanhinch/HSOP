<?php

namespace App\Models;

use App\Models\Concerns\HasTestData;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SiteVisitSubmission extends Model
{
    use HasTestData;

    protected $fillable = [
        'site_visit_id',
        'lead_id',
        'contractor_id',
        'status',
        'measurements',
        'materials_notes',
        'labour_estimate',
        'crew_size',
        'duration_estimate',
        'assumptions',
        'exclusions',
        'contractor_price',
        'price_notes',
        'price_submitted_at',
        'visit_completed_at',
    ];

    protected function casts(): array
    {
        return [
            'measurements' => 'array',
            'contractor_price' => 'decimal:2',
            'price_submitted_at' => 'datetime',
            'visit_completed_at' => 'datetime',
        ];
    }

    public function siteVisit(): BelongsTo
    {
        return $this->belongsTo(SiteVisit::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function contractor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'contractor_id');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(SiteVisitPhoto::class);
    }
}
