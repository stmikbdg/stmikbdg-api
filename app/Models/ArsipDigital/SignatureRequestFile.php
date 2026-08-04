<?php

namespace App\Models\ArsipDigital;

class SignatureRequestFile extends ArsipDigitalModel
{
    protected $table = 'arsip_digital.signature_request_files';

    protected $primaryKey = 'signature_request_file_id';

    protected $guarded = [];

    protected $casts = ['source_size_bytes' => 'integer', 'signed_at' => 'datetime'];

    public function request()
    {
        return $this->belongsTo(SignatureRequest::class, 'signature_request_id', 'signature_request_id');
    }

    public function source()
    {
        return $this->belongsTo(ArchiveFile::class, 'source_file_id', 'file_id');
    }

    public function session()
    {
        return $this->belongsTo(PdfSignSession::class, 'sign_session_id', 'sign_session_id');
    }
}
