<?php

namespace App\Models\ArsipDigital;

class InstitutionalArchiveVerification extends ArsipDigitalModel
{
    protected $table = 'arsip_digital.institutional_archive_verifications';

    protected $primaryKey = 'institutional_archive_verification_id';

    protected $guarded = ['institutional_archive_verification_id'];

    protected $hidden = ['institutional_archive_verification_id', 'institutional_archive_id', 'source_file_id', 'token_hash', 'status', 'failure_message', 'public_metadata', 'source_checksum_sha256', 'verified_checksum_sha256', 'version_number', 'issuer_user_id', 'issuer_name_snapshot', 'storage_disk', 'storage_path', 'original_filename', 'display_filename', 'mime_type', 'file_size_bytes', 'issued_at', 'processed_at', 'replaced_at', 'revoked_at', 'replaced_by_verification_id', 'created_at', 'updated_at'];

    protected $appends = ['verification_summary'];

    protected $casts = [
        'public_metadata' => 'array',
        'version_number' => 'integer',
        'file_size_bytes' => 'integer',
        'issued_at' => 'datetime',
        'processed_at' => 'datetime',
        'replaced_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function getVerificationSummaryAttribute(): array
    {
        return [
            'verification_id' => $this->getKey(),
            'status' => $this->status,
            'failure_message' => $this->status === 'failed' ? ($this->failure_message ?: 'Pemrosesan PDF terverifikasi gagal.') : null,
            'source_checksum_sha256' => $this->source_checksum_sha256,
            'verified_checksum_sha256' => $this->verified_checksum_sha256,
            'version_number' => $this->version_number,
            'issuer_name' => $this->issuer_name_snapshot,
            'issued_at' => $this->issued_at,
            'processed_at' => $this->processed_at,
        ];
    }

    public function archive()
    {
        return $this->belongsTo(InstitutionalArchive::class, 'institutional_archive_id', 'institutional_archive_id');
    }

    public function sourceFile()
    {
        return $this->belongsTo(ArchiveFile::class, 'source_file_id', 'file_id');
    }

    public function replacement()
    {
        return $this->belongsTo(self::class, 'replaced_by_verification_id', 'institutional_archive_verification_id');
    }
}
