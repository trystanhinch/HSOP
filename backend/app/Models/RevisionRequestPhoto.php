<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RevisionRequestPhoto extends Model
{
    protected $fillable = ['revision_request_id', 'file_name', 'file_url'];

    public function revisionRequest(): BelongsTo
    {
        return $this->belongsTo(RevisionRequest::class);
    }
}
