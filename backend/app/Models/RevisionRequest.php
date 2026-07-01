<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RevisionRequest extends Model
{
    protected $fillable = ['job_id', 'requested_by', 'description', 'status'];

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(RevisionRequestPhoto::class);
    }
}
