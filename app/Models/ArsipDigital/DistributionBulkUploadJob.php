<?php

namespace App\Models\ArsipDigital;

class DistributionBulkUploadJob extends ArsipDigitalModel
{
    protected $table = 'arsip_digital.distribution_bulk_upload_jobs';
    protected $primaryKey = 'bulk_upload_job_id';
    protected $guarded = ['bulk_upload_job_id'];

    protected $casts = [
        'file_size_bytes' => 'integer',
        'summary' => 'array',
        'expires_at' => 'datetime',
        'processed_at' => 'datetime',
        'confirmed_at' => 'datetime',
    ];

    public function distribution()
    {
        return $this->belongsTo(Distribution::class, 'distribution_id', 'distribution_id');
    }

    public function entries()
    {
        return $this->hasMany(DistributionBulkUploadEntry::class, 'bulk_upload_job_id', 'bulk_upload_job_id');
    }
}
