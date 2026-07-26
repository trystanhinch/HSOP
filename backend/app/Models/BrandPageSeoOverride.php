<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BrandPageSeoOverride extends Model
{
    protected $fillable = [
        'brand_id',
        'page_key',
        'title',
        'description',
        'og_image',
    ];

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }
}
