<?php

namespace App\Models\ArsipDigital;

use Illuminate\Database\Eloquent\SoftDeletes;

class ArchiveRequest extends ArsipDigitalModel
{
    use SoftDeletes;

    protected $table = 'arsip_digital.requests';
    protected $primaryKey = 'request_id';
    protected $guarded = ['request_id'];

    protected $casts = [
        'target_filters' => 'array',
        'target_identifiers' => 'array',
        'target_segment_ids' => 'array',
        'allowed_extensions' => 'array',
        'requires_verification' => 'boolean',
        'allow_file_reuse' => 'boolean',
        'allow_inactive_upload' => 'boolean',
        'deadline_at' => 'datetime',
        'close_after_deadline' => 'boolean',
        'published_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function assignments()
    {
        return $this->hasMany(RequestAssignment::class, 'request_id', 'request_id');
    }
}
