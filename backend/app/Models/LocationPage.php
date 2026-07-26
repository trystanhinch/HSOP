<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LocationPage extends Model
{
    protected $fillable = [
        'brand_id',
        'slug',
        'city_name',
        'region',
        'content',
        'seo_title',
        'seo_description',
        'status',
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
            'city_name' => $this->city_name,
            'region' => $this->region,
            'content' => $this->content ?? [],
            'seo_title' => $this->seo_title,
            'seo_description' => $this->seo_description,
            'status' => $this->status,
        ];
    }
}
