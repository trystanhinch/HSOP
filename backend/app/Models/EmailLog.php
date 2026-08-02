<?php

namespace App\Models;

use App\Models\Concerns\HasTestData;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailLog extends Model
{
    use HasTestData;

    protected $fillable = [
        'to_email',
        'recipient_normalized',
        'user_id',
        'trigger_event',
        'related_job_id',
        'related_lead_id',
        'brand_id',
        'subject',
        'message_body',
        'status',
        'provider_message_id',
        'error_message',
        'error_code',
        'error_plain',
        'attempt_count',
        'retry_of_id',
        'idempotency_key',
        'correlation_id',
        'correction_path',
        'is_critical',
        'is_test_data',
    ];

    protected function casts(): array
    {
        return [
            'is_critical' => 'boolean',
            'is_test_data' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class, 'related_job_id');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'related_lead_id');
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function retryOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'retry_of_id');
    }
}
