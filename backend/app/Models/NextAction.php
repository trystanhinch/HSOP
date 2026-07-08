<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class NextAction extends Model
{
    protected $fillable = [
        'subject_type',
        'subject_id',
        'action_description',
        'responsible_role',
        'responsible_user_id',
        'due_at',
        'status',
        'last_action_at',
        'escalation_rule',
    ];

    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'last_action_at' => 'datetime',
        ];
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function responsibleUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }
}
