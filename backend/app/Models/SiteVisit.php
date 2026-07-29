<?php

namespace App\Models;

use App\Models\Concerns\HasTestData;
use App\Casts\DateOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SiteVisit extends Model
{
    use HasTestData;

    protected $fillable = [
        'lead_id', 'pm_id', 'contractor_id', 'customer_id',
        'visit_date', 'visit_time', 'notes', 'status',
        'accepted_at', 'declined_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'visit_date' => DateOnly::class,
            'accepted_at' => 'datetime',
            'declined_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function pm(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pm_id');
    }

    public function contractor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'contractor_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function submission(): HasOne
    {
        return $this->hasOne(SiteVisitSubmission::class);
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(SiteVisitSubmission::class);
    }
}
