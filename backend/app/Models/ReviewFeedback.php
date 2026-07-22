<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class ReviewFeedback extends Model
{
    protected $table = 'review_feedback';

    protected $fillable = [
        'job_id',
        'customer_id',
        'pm_id',
        'contractor_id',
        'star_rating',
        'comment',
        'issue_category',
        'photo_url',
        'follow_up_status',
        'resolution_notes',
        'google_review_shown',
        'submitted_at',
    ];

    protected function casts(): array
    {
        return [
            'google_review_shown' => 'boolean',
            'submitted_at' => 'datetime',
        ];
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function pm(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pm_id');
    }

    public function contractor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'contractor_id');
    }

    public function nextActions(): MorphMany
    {
        return $this->morphMany(NextAction::class, 'subject');
    }

    public function needsFollowUp(): bool
    {
        return $this->star_rating < 5
            && in_array($this->follow_up_status, ['new', 'pm_notified', 'customer_contacted', 'escalated'], true);
    }
}
