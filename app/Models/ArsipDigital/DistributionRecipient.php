<?php

namespace App\Models\ArsipDigital;

use Illuminate\Database\Eloquent\SoftDeletes;

class DistributionRecipient extends ArsipDigitalModel
{
    use SoftDeletes;

    protected $table = 'arsip_digital.distribution_recipients';

    protected $primaryKey = 'recipient_id';

    protected $guarded = ['recipient_id'];

    protected $casts = [
        'metadata' => 'array',
        'download_count' => 'integer',
        'first_downloaded_at' => 'datetime',
        'last_downloaded_at' => 'datetime',
    ];

    public function distribution()
    {
        return $this->belongsTo(Distribution::class, 'distribution_id', 'distribution_id');
    }

    public function file()
    {
        return $this->belongsTo(ArchiveFile::class, 'file_id', 'file_id');
    }
}
