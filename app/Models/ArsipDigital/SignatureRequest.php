<?php

namespace App\Models\ArsipDigital;

class SignatureRequest extends ArsipDigitalModel
{
    protected $table = 'arsip_digital.signature_requests';

    protected $primaryKey = 'signature_request_id';

    protected $guarded = [];

    protected $casts = ['expires_at' => 'datetime', 'finished_at' => 'datetime'];

    public function files()
    {
        return $this->hasMany(SignatureRequestFile::class, 'signature_request_id', 'signature_request_id');
    }
}
