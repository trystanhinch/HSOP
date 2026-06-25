<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JobUpdate extends Model
{
    protected $fillable = ['job_id', 'posted_by', 'poster_role', 'update_text', 'visibility'];

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(JobUpdatePhoto::class);
    }
}
