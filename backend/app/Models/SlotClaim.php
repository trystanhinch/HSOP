<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SlotClaim extends Model
{
    protected $fillable = [
        'brand_id',
        'resource_key',
        'slot_start',
        'slot_end',
        'claim_type',
        'claim_id',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'slot_start' => 'datetime',
            'slot_end' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }
}
