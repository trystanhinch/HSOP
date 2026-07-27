<?php

namespace App\Models;

use App\Models\Concerns\HasTestData;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IntakeQuarantine extends Model
{
    use HasTestData;

    protected $table = 'intake_quarantine';

    protected $fillable = [
        'channel',
        'status',
        'mailbox_email',
        'gmail_message_id',
        'gmail_thread_id',
        'message_id_hash',
        'raw_email',
        'subject',
        'from_header',
        'email_format',
        'parsed_fields',
        'field_confidence',
        'validation_errors',
        'quarantine_reason',
        'company_source_id',
        'duplicate_group_key',
        'duplicate_of_quarantine_id',
        'converted_lead_id',
        'reviewed_by',
        'reviewed_at',
        'ignore_reason',
        'is_test_data',
    ];

    protected function casts(): array
    {
        return [
            'parsed_fields' => 'array',
            'field_confidence' => 'array',
            'validation_errors' => 'array',
            'reviewed_at' => 'datetime',
            'is_test_data' => 'boolean',
        ];
    }

    public function companySource(): BelongsTo
    {
        return $this->belongsTo(CompanySource::class);
    }

    public function convertedLead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'converted_lead_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(IntakeAuditLog::class, 'intake_quarantine_id');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
}
