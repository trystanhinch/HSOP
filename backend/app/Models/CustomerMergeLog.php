<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerMergeLog extends Model
{
    protected $fillable = [
        'primary_customer_id',
        'merged_customer_ids',
        'actor_id',
        'pre_merge_snapshot',
        'field_choices',
        'reassignment_counts',
        'status',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'merged_customer_ids' => 'array',
            'pre_merge_snapshot' => 'array',
            'field_choices' => 'array',
            'reassignment_counts' => 'array',
        ];
    }

    public function primaryCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'primary_customer_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
