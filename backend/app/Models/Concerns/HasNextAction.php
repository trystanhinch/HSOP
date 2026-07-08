<?php

namespace App\Models\Concerns;

use App\Models\NextAction;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

trait HasNextAction
{
    public function nextActions(): MorphMany
    {
        return $this->morphMany(NextAction::class, 'subject');
    }

    /** Latest open next-action for this record (pending, overdue, or escalated). */
    public function pendingNextAction(): MorphOne
    {
        return $this->morphOne(NextAction::class, 'subject')
            ->whereIn('status', ['pending', 'overdue', 'escalated'])
            ->latestOfMany();
    }
}
