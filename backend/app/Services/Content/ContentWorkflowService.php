<?php

namespace App\Services\Content;

use App\Models\BrandPage;
use App\Models\ContentRevision;
use App\Models\LocationPage;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A-36 — Content workflow states, revisions (A-32-style), publish guards.
 *
 * States: draft → review → approved → scheduled|published
 */
class ContentWorkflowService
{
    public const STATUSES = ['draft', 'review', 'approved', 'scheduled', 'published'];

    public const SECTION_TYPES = [
        'faq',
        'gallery',
        'before_after',
        'testimonials',
        'trust_blocks',
        'service_areas',
        'internal_links',
    ];

    public const DUPLICATE_SIMILARITY_THRESHOLD = 0.85;

    /**
     * @return array{ok: bool, message: ?string, duplicates: list<array<string, mixed>>, empty: bool}
     */
    public function publishGuard(LocationPage|BrandPage $page, bool $acknowledgeDuplicate = false): array
    {
        $content = $page->content ?? [];
        $bodyField = trim((string) ($content['body'] ?? ''));
        $empty = $page instanceof LocationPage && $bodyField === '';
        $body = $this->extractBodyText($page);

        if ($empty) {
            return [
                'ok' => false,
                'message' => 'Cannot publish a location page with an empty body.',
                'duplicates' => [],
                'empty' => true,
            ];
        }

        $duplicates = [];
        if ($page instanceof LocationPage && ! $empty) {
            $duplicates = $this->findNearDuplicateLocations($page, $body);
            if ($duplicates !== [] && ! $acknowledgeDuplicate) {
                return [
                    'ok' => false,
                    'message' => 'Near-duplicate location copy detected. Acknowledge the warning to publish anyway, or rewrite the body.',
                    'duplicates' => $duplicates,
                    'empty' => false,
                    'duplicate_warning' => true,
                ];
            }
        }

        return [
            'ok' => true,
            'message' => null,
            'duplicates' => $duplicates,
            'empty' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function transition(
        LocationPage|BrandPage $page,
        string $action,
        User $actor,
        array $options = [],
    ): array {
        $from = $page->status ?: 'draft';
        $to = match ($action) {
            'save_draft' => 'draft',
            'submit_review' => 'review',
            'request_changes' => 'draft',
            'approve' => 'approved',
            'schedule' => 'scheduled',
            'publish' => 'published',
            'unpublish' => 'draft',
            default => throw ValidationException::withMessages(['action' => ["Unknown action [{$action}]"]]),
        };

        $this->assertTransitionAllowed($actor, $from, $to, $action);

        if (in_array($to, ['published', 'scheduled'], true)) {
            $guard = $this->publishGuard(
                $page,
                (bool) ($options['acknowledge_duplicate'] ?? false)
            );
            if (! $guard['ok']) {
                throw new HttpResponseException(response()->json([
                    'message' => $guard['message'],
                    'empty' => $guard['empty'],
                    'duplicate_warning' => $guard['duplicate_warning'] ?? false,
                    'duplicates' => $guard['duplicates'],
                    'errors' => ['status' => [$guard['message']]],
                ], 422));
            }
        }

        if ($action === 'approve' && $actor->role !== 'owner') {
            throw ValidationException::withMessages([
                'action' => ['Only an owner can approve content for publish.'],
            ]);
        }

        if ($action === 'publish' && $actor->role === 'content_editor' && $from !== 'approved' && $from !== 'scheduled') {
            throw ValidationException::withMessages([
                'action' => ['SEO editors can publish only after approval (or from scheduled).'],
            ]);
        }

        // Snapshot before change (A-32-style immutable history)
        $revision = $this->recordRevision($page, $actor, $action, $options['notes'] ?? null);

        $updates = ['status' => $to];
        if ($action === 'submit_review') {
            $updates['author_id'] = $actor->id;
        }
        if ($action === 'approve') {
            $updates['reviewer_id'] = $actor->id;
            $updates['approved_at'] = now();
        }
        if ($action === 'schedule') {
            $scheduledAt = $options['scheduled_at'] ?? null;
            if (! $scheduledAt) {
                throw ValidationException::withMessages(['scheduled_at' => ['Scheduled publish requires scheduled_at.']]);
            }
            $updates['scheduled_at'] = $scheduledAt;
        }
        if ($action === 'publish') {
            $updates['published_at'] = now();
            $updates['scheduled_at'] = null;
        }

        $page->update($updates);

        return [
            'page' => $page->fresh(),
            'from' => $from,
            'to' => $to,
            'revision' => $revision,
        ];
    }

    public function recordRevision(
        LocationPage|BrandPage $page,
        User $actor,
        string $action,
        ?string $notes = null,
        ?User $reviewer = null,
    ): ContentRevision {
        $next = ((int) ($page->revision_number ?? 1)) + ($action === 'created' ? 0 : 1);
        if ($action !== 'created') {
            $page->update(['revision_number' => $next]);
        }

        return ContentRevision::create([
            'subject_type' => $page->getMorphClass(),
            'subject_id' => $page->id,
            'revision_number' => $next,
            'snapshot' => $this->snapshot($page),
            'status_at_revision' => $page->status,
            'author_id' => $actor->id,
            'reviewer_id' => $reviewer?->id ?? $page->reviewer_id,
            'action' => $action,
            'notes' => $notes,
        ]);
    }

    /**
     * Roll back to a prior revision snapshot (creates a new revision recording the rollback).
     */
    public function rollback(LocationPage|BrandPage $page, ContentRevision $revision, User $actor): ContentRevision
    {
        if ((int) $revision->subject_id !== (int) $page->id
            || $revision->subject_type !== $page->getMorphClass()) {
            throw ValidationException::withMessages(['revision' => ['Revision does not belong to this page.']]);
        }

        $snap = $revision->snapshot ?? [];
        $fillable = array_intersect_key($snap, array_flip($page->getFillable()));
        // Keep identity fields stable
        unset($fillable['brand_id'], $fillable['slug']);

        $page->fill($fillable);
        $page->save();

        return $this->recordRevision($page, $actor, 'rollback', 'Restored revision #'.$revision->revision_number);
    }

    /**
     * Preview payload identical to public production payload.
     *
     * @return array<string, mixed>
     */
    public function previewPayload(LocationPage|BrandPage $page): array
    {
        return $page->publicPayload();
    }

    public function isPubliclyVisible(LocationPage|BrandPage $page): bool
    {
        if ($page->status === 'published') {
            return true;
        }
        if ($page->status === 'scheduled' && $page->scheduled_at && $page->scheduled_at->lte(now())) {
            return true;
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(LocationPage|BrandPage $page): array
    {
        return $page->only($page->getFillable());
    }

    private function extractBodyText(LocationPage|BrandPage $page): string
    {
        $content = $page->content ?? [];
        $parts = [
            $content['body'] ?? '',
            $content['headline'] ?? '',
            is_string($content['lede'] ?? null) ? $content['lede'] : '',
        ];
        if (! empty($page->sections) && is_array($page->sections)) {
            $parts[] = json_encode($page->sections);
        }

        return trim(implode("\n", array_filter($parts)));
    }

    /**
     * @return list<array{id: int, city_name: string, similarity: float}>
     */
    private function findNearDuplicateLocations(LocationPage $page, string $body): array
    {
        $normalized = $this->normalizeText($body);
        if (strlen($normalized) < 40) {
            // Short bodies still checked but threshold is strict
        }

        $others = LocationPage::query()
            ->where('brand_id', $page->brand_id)
            ->where('id', '!=', $page->id)
            ->whereIn('status', ['published', 'approved', 'scheduled', 'review'])
            ->get();

        $hits = [];
        foreach ($others as $other) {
            $otherBody = $this->normalizeText($this->extractBodyText($other));
            if ($otherBody === '') {
                continue;
            }
            similar_text($normalized, $otherBody, $percent);
            $ratio = $percent / 100;
            if ($ratio >= self::DUPLICATE_SIMILARITY_THRESHOLD) {
                $hits[] = [
                    'id' => $other->id,
                    'city_name' => $other->city_name,
                    'similarity' => round($ratio, 3),
                ];
            }
        }

        return $hits;
    }

    private function normalizeText(string $text): string
    {
        $text = Str::lower($text);
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;

        return trim($text);
    }

    private function assertTransitionAllowed(User $actor, string $from, string $to, string $action): void
    {
        $allowed = [
            'draft' => ['review', 'draft', 'approved'], // owner may fast-approve
            'review' => ['draft', 'approved'],
            'approved' => ['scheduled', 'published', 'draft'],
            'scheduled' => ['published', 'draft', 'approved'],
            'published' => ['draft'],
        ];

        if ($to === $from && $action === 'save_draft') {
            return;
        }

        if (! in_array($to, $allowed[$from] ?? [], true)) {
            throw ValidationException::withMessages([
                'action' => ["Cannot transition from {$from} to {$to}."],
            ]);
        }

        if ($actor->role === 'content_editor' && $to === 'approved') {
            throw ValidationException::withMessages([
                'action' => ['SEO editors cannot self-approve content.'],
            ]);
        }
    }
}
