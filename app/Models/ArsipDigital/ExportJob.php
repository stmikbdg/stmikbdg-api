<?php

namespace App\Models\ArsipDigital;

class ExportJob extends ArsipDigitalModel
{
    protected $table = 'arsip_digital.export_jobs';
    protected $primaryKey = 'export_job_id';
    protected $guarded = ['export_job_id'];

    protected $casts = [
        'filters' => 'array',
        'file_size_bytes' => 'integer',
        'expires_at' => 'datetime',
        'completed_at' => 'datetime',
    ];
}
