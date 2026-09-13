<?php

namespace App\Models\ArsipDigital;

use Illuminate\Database\Eloquent\SoftDeletes;

class ArchiveFile extends ArsipDigitalModel
{
    use SoftDeletes;

    protected $table = 'arsip_digital.files';

    protected $primaryKey = 'file_id';

    protected $guarded = ['file_id'];

    protected $appends = ['storage_available'];

    protected $casts = [
        'file_size_bytes' => 'integer',
        'version_number' => 'integer',
        'is_current' => 'boolean',
        'metadata' => 'array',
    ];

    public function getStorageAvailableAttribute(): bool
    {
        return $this->storage_availability !== 'missing';
    }

    public function requestFile()
    {
        return $this->hasOne(RequestFile::class, 'file_id', 'file_id');
    }

    public function category()
    {
        return $this->belongsTo(Category::class, 'category_id', 'category_id');
    }

    public function institutionalArchive()
    {
        return $this->belongsTo(InstitutionalArchive::class, 'institutional_archive_id', 'institutional_archive_id');
    }

    public function institutionalVerification()
    {
        return $this->hasOne(InstitutionalArchiveVerification::class, 'source_file_id', 'file_id');
    }
}
