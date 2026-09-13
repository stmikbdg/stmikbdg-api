<?php

namespace App\Models\ArsipDigital;

use Illuminate\Database\Eloquent\SoftDeletes;

class InstitutionalArchive extends ArsipDigitalModel
{
    use SoftDeletes;

    protected $table = 'arsip_digital.institutional_archives';

    protected $primaryKey = 'institutional_archive_id';

    protected $guarded = ['institutional_archive_id', 'document_number_normalized'];

    protected $casts = [
        'document_year' => 'integer',
        'document_date' => 'date',
        'received_date' => 'date',
        'tags' => 'array',
    ];

    public function unit()
    {
        return $this->belongsTo(InstitutionalUnit::class, 'unit_id', 'unit_id');
    }

    public function category()
    {
        return $this->belongsTo(Category::class, 'category_id', 'category_id');
    }

    public function currentFile()
    {
        return $this->belongsTo(ArchiveFile::class, 'current_file_id', 'file_id');
    }

    public function currentVerification()
    {
        return $this->hasOne(InstitutionalArchiveVerification::class, 'source_file_id', 'current_file_id');
    }

    public function files()
    {
        return $this->hasMany(ArchiveFile::class, 'institutional_archive_id', 'institutional_archive_id');
    }

    public function distributions()
    {
        return $this->hasMany(Distribution::class, 'institutional_archive_id', 'institutional_archive_id');
    }
}
