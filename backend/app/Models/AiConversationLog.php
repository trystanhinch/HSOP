<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiConversationLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'intake_session_id',
        'lead_id',
        'turn_number',
        'role',
        'content',
        'tool_calls',
        'tool_results',
        'ai_provider',
        'ai_model',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'tool_calls' => 'array',
            'tool_results' => 'array',
            'created_at' => 'datetime',
            'turn_number' => 'integer',
        ];
    }

    public function intakeSession(): BelongsTo
    {
        return $this->belongsTo(IntakeSession::class, 'intake_session_id');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}
