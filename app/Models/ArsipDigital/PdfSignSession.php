<?php

namespace App\Models\ArsipDigital;

class PdfSignSession extends ArsipDigitalModel
{
    protected $table = 'arsip_digital.pdf_sign_sessions';

    protected $primaryKey = 'sign_session_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'expires_at' => 'datetime',
    ];
}
