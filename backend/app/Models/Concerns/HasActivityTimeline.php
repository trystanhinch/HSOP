<?php

namespace App\Models\Concerns;

use App\Models\ActivityTimelineEntry;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasActivityTimeline
{
    public function timelineEntries(): MorphMany
    {
        return $this->morphMany(ActivityTimelineEntry::class, 'subject')
            ->orderByDesc('occurred_at');
    }
}
