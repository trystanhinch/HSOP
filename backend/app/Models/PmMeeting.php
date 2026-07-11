<?php

namespace App\Models;

use App\Casts\DateOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PmMeeting extends Model
{
    protected $fillable = [
        'title',
        'meeting_date',
        'meeting_time',
        'notes',
        'pm_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'meeting_date' => DateOnly::class,
        ];
    }

    public function pm(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pm_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
