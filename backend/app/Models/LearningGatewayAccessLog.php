<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Milestone 6B Phase 1 — append-only access ledger for /api/learning-gateway/*.
 */
class LearningGatewayAccessLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'actor_user_id',
        'personal_access_token_id',
        'token_name',
        'ability',
        'tool',
        'http_method',
        'path',
        'parameters',
        'response_record_count',
        'outcome',
        'http_status',
        'ip',
        'trace_id',
        'denial_reason',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'parameters' => 'array',
            'created_at' => 'datetime',
            'response_record_count' => 'integer',
            'http_status' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new LogicException('learning_gateway_access_logs is append-only; updates are forbidden.');
        });

        static::deleting(function () {
            throw new LogicException('learning_gateway_access_logs is append-only; deletes are forbidden.');
        });
    }
}
