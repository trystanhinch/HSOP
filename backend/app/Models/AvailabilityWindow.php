<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AvailabilityWindow extends Model
{
    protected $fillable = [
        'brand_id',
        'pm_id',
        'contractor_id',
        'service_category',
        'day_of_week',
        'specific_date',
        'start_time',
        'end_time',
        'slot_duration_minutes',
        'timezone',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'day_of_week' => 'integer',
            'specific_date' => 'date',
            'slot_duration_minutes' => 'integer',
        ];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function pm(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pm_id');
    }

    public function contractor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'contractor_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function resourceKey(): string
    {
        if ($this->contractor_id) {
            return 'contractor:'.$this->contractor_id;
        }
        if ($this->pm_id) {
            return 'pm:'.$this->pm_id;
        }

        return 'brand:'.$this->brand_id;
    }
}
