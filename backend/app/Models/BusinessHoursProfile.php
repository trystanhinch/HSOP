<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessHoursProfile extends Model
{
    protected $fillable = [
        'brand_id',
        'company_id',
        'name',
        'timezone',
        'weekly_hours',
        'holidays',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'weekly_hours' => 'array',
            'holidays' => 'array',
            'is_default' => 'boolean',
        ];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * Default Mon–Fri 09:00–17:00.
     *
     * @return array<string, list<list<string>>>
     */
    public static function defaultWeeklyHours(): array
    {
        $open = [['09:00', '17:00']];

        return [
            '1' => $open,
            '2' => $open,
            '3' => $open,
            '4' => $open,
            '5' => $open,
            '6' => [],
            '7' => [],
        ];
    }
}
