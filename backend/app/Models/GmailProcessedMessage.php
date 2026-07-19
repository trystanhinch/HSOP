<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GmailProcessedMessage extends Model
{
    protected $fillable = [
        'gmail_message_id',
        'gmail_thread_id',
        'mailbox_email',
        'lead_id',
        'status',
        'error',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'processed_at' => 'datetime',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}
