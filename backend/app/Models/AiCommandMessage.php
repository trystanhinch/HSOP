<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiCommandMessage extends Model
{
    protected $fillable = [
        'session_id',
        'role',
        'content',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(AiCommandSession::class, 'session_id');
    }
}
