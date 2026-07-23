<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingHold extends Model
{
    protected $fillable = [
        'brand_id',
        'intake_session_id',
        'lead_id',
        'hold_token',
        'resource_key',
        'pm_id',
        'contractor_id',
        'service_category',
        'slot_start',
        'slot_end',
        'status',
        'held_until',
    ];

    protected function casts(): array
    {
        return [
            'slot_start' => 'datetime',
            'slot_end' => 'datetime',
            'held_until' => 'datetime',
        ];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function intakeSession(): BelongsTo
    {
        return $this->belongsTo(IntakeSession::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function isActiveHold(): bool
    {
        return $this->status === 'held' && $this->held_until && $this->held_until->isFuture();
    }
}
