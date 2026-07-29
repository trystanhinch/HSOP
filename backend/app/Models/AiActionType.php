<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiActionType extends Model
{
    protected $fillable = [
        'action_key',
        'label',
        'permission_level',
        'requires_human_approval',
        'risk_level',
        'module',
        'hard_approval_floor',
        'modes_available',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'requires_human_approval' => 'boolean',
            'hard_approval_floor' => 'boolean',
            'modes_available' => 'array',
        ];
    }
}
