<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntakeAuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'intake_quarantine_id',
        'lead_id',
        'actor_type',
        'actor_id',
        'decision',
        'reason',
        'source_text',
        'confidence',
        'metadata',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'confidence' => 'array',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function quarantine(): BelongsTo
    {
        return $this->belongsTo(IntakeQuarantine::class, 'intake_quarantine_id');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
