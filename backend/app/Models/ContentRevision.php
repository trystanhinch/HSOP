<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ContentRevision extends Model
{
    protected $fillable = [
        'subject_type',
        'subject_id',
        'revision_number',
        'snapshot',
        'status_at_revision',
        'author_id',
        'reviewer_id',
        'action',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
            'revision_number' => 'integer',
        ];
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }
}
