<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Milestone 6A.4 — Owner monitoring alerts (AlertDispatcher persistence channel).
 */
class Alert extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'severity',
        'message',
        'context',
        'acknowledged_at',
        'acknowledged_by',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'acknowledged_at' => 'datetime',
            'created_at' => 'datetime',
            'acknowledged_by' => 'integer',
        ];
    }

    public function acknowledger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }
}
