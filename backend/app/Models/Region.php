<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Region extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'parent_region_id',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_region_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_region_id');
    }

    public function properties(): HasMany
    {
        return $this->hasMany(Property::class);
    }
}
