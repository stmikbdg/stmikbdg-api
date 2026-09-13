<?php

namespace App\Models\ArsipDigital;

use Illuminate\Database\Eloquent\SoftDeletes;

class Distribution extends ArsipDigitalModel
{
    use SoftDeletes;

    protected $table = 'arsip_digital.distributions';

    protected $primaryKey = 'distribution_id';

    protected $guarded = ['distribution_id'];

    protected $casts = [
        'official_document_id' => 'integer',
        'institutional_archive_id' => 'integer',
        'source_file_id' => 'integer',
        'target_count' => 'integer',
        'target_filters' => 'array',
        'target_identifiers' => 'array',
        'target_segment_ids' => 'array',
        'published_at' => 'datetime',
        'withdrawn_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function officialDocument()
    {
        return $this->belongsTo(OfficialDocument::class, 'official_document_id', 'official_document_id');
    }

    public function institutionalArchive()
    {
        return $this->belongsTo(InstitutionalArchive::class, 'institutional_archive_id', 'institutional_archive_id');
    }

    public function sourceFile()
    {
        return $this->belongsTo(ArchiveFile::class, 'source_file_id', 'file_id');
    }

    public function originalDistribution()
    {
        return $this->belongsTo(self::class, 'original_distribution_id', 'distribution_id');
    }

    public function corrections()
    {
        return $this->hasMany(self::class, 'original_distribution_id', 'distribution_id');
    }

    public function recipients()
    {
        return $this->hasMany(DistributionRecipient::class, 'distribution_id', 'distribution_id');
    }
}
