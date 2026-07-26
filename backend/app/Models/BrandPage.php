<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BrandPage extends Model
{
    public const TEMPLATE_TYPES = ['simple', 'home', 'service', 'quote'];

    protected $fillable = [
        'brand_id',
        'slug',
        'title',
        'template_type',
        'content',
        'seo_title',
        'seo_description',
        'og_image',
        'status',
        'source_key',
    ];

    protected function casts(): array
    {
        return [
            'content' => 'array',
        ];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function isPublished(): bool
    {
        return $this->status === 'published';
    }

    /**
     * @return array<string, mixed>
     */
    public function publicPayload(): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'title' => $this->title,
            'template_type' => $this->template_type,
            'content' => $this->content ?? [],
            'seo_title' => $this->seo_title,
            'seo_description' => $this->seo_description,
            'og_image' => $this->og_image,
            'status' => $this->status,
            'source_key' => $this->source_key,
        ];
    }
}
