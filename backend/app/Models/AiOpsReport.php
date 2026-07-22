<?php

namespace App\Models;

use App\Casts\DateOnly;
use Illuminate\Database\Eloquent\Model;

class AiOpsReport extends Model
{
    protected $fillable = [
        'report_date',
        'period',
        'summary_text',
        'raw_metrics',
        'provider',
    ];

    protected function casts(): array
    {
        return [
            'report_date' => DateOnly::class,
            'raw_metrics' => 'array',
        ];
    }
}
