<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntakeSession extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'brand_id',
        'session_token',
        'conversation_state',
        'converted_lead_id',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'conversation_state' => 'array',
            'expires_at' => 'datetime',
        ];
    }

    public function convertedLead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'converted_lead_id');
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isConverted(): bool
    {
        return $this->converted_lead_id !== null;
    }

    /**
     * @return list<array{role: string, content: string, at?: string}>
     */
    public function messages(): array
    {
        $state = $this->conversation_state ?? [];

        return is_array($state['messages'] ?? null) ? $state['messages'] : [];
    }

    /**
     * @param  array<string, mixed>  $patch
     */
    public function mergeConversationState(array $patch): void
    {
        $state = $this->conversation_state ?? [];
        $this->conversation_state = array_replace_recursive($state, $patch);
        $this->save();
    }
}
