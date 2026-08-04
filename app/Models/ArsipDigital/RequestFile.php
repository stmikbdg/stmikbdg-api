<?php

namespace App\Models\ArsipDigital;

use Illuminate\Database\Eloquent\SoftDeletes;

class RequestFile extends ArsipDigitalModel
{
    use SoftDeletes;

    protected $table = 'arsip_digital.request_files';
    protected $primaryKey = 'request_file_id';
    protected $guarded = ['request_file_id'];

    protected $casts = [
        'is_late' => 'boolean',
        'is_current' => 'boolean',
        'reviewed_at' => 'datetime',
    ];

    public function file()
    {
        return $this->belongsTo(ArchiveFile::class, 'file_id', 'file_id');
    }

    public function assignment()
    {
        return $this->belongsTo(RequestAssignment::class, 'assignment_id', 'assignment_id');
    }

    public function request()
    {
        return $this->belongsTo(ArchiveRequest::class, 'request_id', 'request_id');
    }
}
