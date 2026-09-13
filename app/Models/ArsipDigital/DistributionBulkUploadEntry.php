<?php

namespace App\Models\ArsipDigital;

class DistributionBulkUploadEntry extends ArsipDigitalModel
{
    protected $table = 'arsip_digital.distribution_bulk_upload_entries';
    protected $primaryKey = 'bulk_upload_entry_id';
    protected $guarded = ['bulk_upload_entry_id'];

    protected $casts = [
        'file_size_bytes' => 'integer',
        'metadata' => 'array',
    ];

    public function job()
    {
        return $this->belongsTo(DistributionBulkUploadJob::class, 'bulk_upload_job_id', 'bulk_upload_job_id');
    }

    public function recipient()
    {
        return $this->belongsTo(DistributionRecipient::class, 'recipient_id', 'recipient_id');
    }
}
