<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiActionLog extends Model
{
    protected $fillable = [
        'trace_id',
        'parent_log_id',
        'trigger_event',
        'actor_id',
        'data_viewed',
        'decision',
        'action_taken',
        'action_key',
        'module',
        'mode',
        'risk_level',
        'ai_model',
        'prompt_version',
        'tokens_prompt',
        'tokens_completion',
        'cost_usd',
        'latency_ms',
        'retry_count',
        'idempotency_key',
        'linked_type',
        'linked_id',
        'brand_id',
        'outcome',
        'is_simulation',
        'approval_log_id',
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
            'is_simulation' => 'boolean',
            'cost_usd' => 'float',
            'tokens_prompt' => 'integer',
            'tokens_completion' => 'integer',
            'latency_ms' => 'integer',
            'retry_count' => 'integer',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_log_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_log_id');
    }

    /**
     * Default activity feed: root incidents only (retries nested via children / retry_count).
     */
    public function scopeRootIncidents($query)
    {
        return $query->whereNull('parent_log_id');
    }

    public function toPublicArray(bool $revealSensitive = false): array
    {
        $arr = $this->toArray();
        if (! $revealSensitive) {
            if (! empty($arr['message_sent'])) {
                $arr['message_sent'] = '[redacted]';
                $arr['message_sent_redacted'] = true;
            }
            if (is_array($arr['data_viewed'] ?? null)) {
                foreach (['message', 'content', 'conversation', 'full_content'] as $k) {
                    if (! empty($arr['data_viewed'][$k]) && is_string($arr['data_viewed'][$k])) {
                        $arr['data_viewed'][$k] = '[redacted]';
                    }
                }
            }
        }

        return $arr;
    }
}
