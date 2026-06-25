<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobUpdatePhoto extends Model
{
    protected $fillable = ['job_update_id', 'file_name', 'file_url', 'file_size'];

    public function jobUpdate(): BelongsTo
    {
        return $this->belongsTo(JobUpdate::class, 'job_update_id');
    }
}
