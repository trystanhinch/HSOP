<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiActionLog extends Model
{
    protected $fillable = [
        'trigger_event',
        'actor_id',
        'data_viewed',
        'decision',
        'action_taken',
        'message_sent',
        'recipient',
        'status_before',
        'status_after',
        'rule_applied',
        'required_human_approval',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'data_viewed' => 'array',
            'required_human_approval' => 'boolean',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
