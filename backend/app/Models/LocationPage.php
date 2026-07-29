<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class LocationPage extends Model
{
    protected $fillable = [
        'brand_id',
        'slug',
        'city_name',
        'region',
        'content',
        'sections',
        'seo_title',
        'seo_description',
        'canonical_url',
        'schema_markup',
        'sitemap_include',
        'robots_noindex',
        'og_image',
        'image_meta',
        'status',
        'scheduled_at',
        'published_at',
        'approved_at',
        'author_id',
        'reviewer_id',
        'revision_number',
    ];

    protected function casts(): array
    {
        return [
            'content' => 'array',
            'sections' => 'array',
            'schema_markup' => 'array',
            'image_meta' => 'array',
            'sitemap_include' => 'boolean',
            'robots_noindex' => 'boolean',
            'scheduled_at' => 'datetime',
            'published_at' => 'datetime',
            'approved_at' => 'datetime',
            'revision_number' => 'integer',
        ];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function revisions(): MorphMany
    {
        return $this->morphMany(ContentRevision::class, 'subject');
    }

    public function isPublished(): bool
    {
        return $this->status === 'published'
            || ($this->status === 'scheduled' && $this->scheduled_at && $this->scheduled_at->lte(now()));
    }

    /**
     * @return array<string, mixed>
     */
    public function publicPayload(): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'city_name' => $this->city_name,
            'region' => $this->region,
            'content' => $this->content ?? [],
            'sections' => $this->sections ?? [],
            'seo_title' => $this->seo_title,
            'seo_description' => $this->seo_description,
            'canonical_url' => $this->canonical_url,
            'schema_markup' => $this->schema_markup,
            'sitemap_include' => $this->sitemap_include ?? true,
            'robots_noindex' => (bool) $this->robots_noindex,
            'og_image' => $this->og_image,
            'image_meta' => $this->image_meta ?? [],
            'status' => $this->status,
            'scheduled_at' => $this->scheduled_at?->toIso8601String(),
            'published_at' => $this->published_at?->toIso8601String(),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'author_id' => $this->author_id,
            'reviewer_id' => $this->reviewer_id,
            'revision_number' => $this->revision_number ?? 1,
        ];
    }
}
