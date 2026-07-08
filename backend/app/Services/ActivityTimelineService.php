<?php

namespace App\Services;

use App\Models\ActivityTimelineEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class ActivityTimelineService
{
    public function record(
        Model $subject,
        string $eventType,
        string $description,
        ?User $actor = null,
        ?array $metadata = null,
    ): ActivityTimelineEntry {
        $actor = $actor ?? auth()->user();
        $actorType = $actor?->isAiSuperAdmin() ? 'ai_super_admin' : 'user';

        return ActivityTimelineEntry::create([
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'event_type' => $eventType,
            'actor_type' => $actorType,
            'actor_id' => $actor?->id,
            'description' => $description,
            'metadata' => $metadata,
            'occurred_at' => now(),
        ]);
    }

    public function forSubject(Model $subject, int $limit = 50)
    {
        return ActivityTimelineEntry::where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey())
            ->with('actorUser:id,name,role')
            ->orderByDesc('occurred_at')
            ->limit($limit)
            ->get();
    }
}
