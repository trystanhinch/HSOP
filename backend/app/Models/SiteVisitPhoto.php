<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteVisitPhoto extends Model
{
    protected $fillable = [
        'site_visit_submission_id',
        'lead_id',
        'job_id',
        'uploaded_by',
        'file_url',
        'file_name',
        'caption',
    ];

    public function submission(): BelongsTo
    {
        return $this->belongsTo(SiteVisitSubmission::class, 'site_visit_submission_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
