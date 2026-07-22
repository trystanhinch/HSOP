<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StripeWebhookEvent extends Model
{
    protected $fillable = [
        'event_id',
        'type',
        'status',
        'invoice_id',
        'payload_meta',
        'error',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'payload_meta' => 'array',
            'processed_at' => 'datetime',
        ];
    }
}
