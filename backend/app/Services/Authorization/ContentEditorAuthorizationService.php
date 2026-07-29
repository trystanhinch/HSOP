<?php

namespace App\Services\Authorization;

use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\ContentEditorBrandAssignment;
use App\Models\User;
use Illuminate\Support\Collection;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A-36 — Brand-scoped content_editor authorization (PM-01/PM-02 pattern).
 *
 * Pivot assignments preferred; legacy users.brand_id still honored when pivot empty.
 */
class ContentEditorAuthorizationService
{
    /**
     * @return Collection<int, int>
     */
    public function assignedBrandIds(User $user): Collection
    {
        if ($user->role !== 'content_editor') {
            return collect();
        }

        $fromPivot = ContentEditorBrandAssignment::query()
            ->where('user_id', $user->id)
            ->pluck('brand_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($fromPivot->isNotEmpty()) {
            return $fromPivot;
        }

        // Legacy single-brand FK
        if ($user->brand_id) {
            return collect([(int) $user->brand_id]);
        }

        return collect();
    }

    public function hasBrandAccess(User $user, int $brandId): bool
    {
        if ($user->role === 'owner') {
            return true;
        }
        if ($user->role !== 'content_editor') {
            return false;
        }

        return $this->assignedBrandIds($user)->contains($brandId);
    }

    public function assertBrandAccess(User $user, ?int $brandId, string $context = 'brand'): void
    {
        if ($user->role !== 'content_editor') {
            return;
        }
        if ($brandId === null || ! $this->hasBrandAccess($user, $brandId)) {
            $this->logDenied($user, 'brand', (int) $brandId, $context, ['brand_id' => $brandId]);
            throw new HttpException(403, 'Unauthorized brand access.');
        }
    }

    public function primaryBrand(User $user): ?Brand
    {
        $ids = $this->assignedBrandIds($user);
        if ($ids->isEmpty()) {
            return null;
        }

        return Brand::query()->find($ids->first());
    }

    /**
     * @param  list<int>  $brandIds
     * @return array{before: list<int>, after: list<int>}
     */
    public function syncAssignments(User $editor, array $brandIds, User $actor): array
    {
        if ($editor->role !== 'content_editor') {
            throw new HttpException(422, 'Brand assignments only apply to content editors.');
        }
        if ($actor->role !== 'owner') {
            throw new HttpException(403, 'Only owners can change content editor brand assignments.');
        }

        $brandIds = collect($brandIds)->map(fn ($id) => (int) $id)->unique()->filter()->values();
        $before = $this->assignedBrandIds($editor)->all();

        ContentEditorBrandAssignment::query()->where('user_id', $editor->id)->delete();
        foreach ($brandIds as $brandId) {
            if (! Brand::query()->whereKey($brandId)->exists()) {
                continue;
            }
            ContentEditorBrandAssignment::create([
                'user_id' => $editor->id,
                'brand_id' => $brandId,
                'assigned_by' => $actor->id,
                'assigned_at' => now(),
            ]);
        }

        // Keep legacy brand_id in sync with first assignment (or null)
        $editor->update(['brand_id' => $brandIds->first()]);

        $after = $this->assignedBrandIds($editor)->all();

        AuditLog::create([
            'user_id' => $actor->id,
            'user_role' => $actor->role,
            'object_type' => 'content_editor_brand_assignment',
            'object_id' => $editor->id,
            'action_type' => 'content_editor_brand_assignments_changed',
            'previous_value' => ['brand_ids' => $before],
            'new_value' => ['brand_ids' => $after],
            'reason' => 'Owner updated content editor brand assignments',
            'created_at' => now(),
        ]);

        return ['before' => $before, 'after' => $after];
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function logDenied(User $user, string $objectType, int $objectId, string $action, array $meta = []): void
    {
        try {
            AuditLog::create([
                'user_id' => $user->id,
                'user_role' => $user->role,
                'object_type' => $objectType,
                'object_id' => $objectId,
                'action_type' => 'content_editor_access_denied',
                'previous_value' => null,
                'new_value' => array_merge(['action' => $action], $meta),
                'reason' => 'Content editor attempted unauthorized access',
                'created_at' => now(),
            ]);
        } catch (\Throwable) {
            // never block request on audit failure
        }
    }
}
