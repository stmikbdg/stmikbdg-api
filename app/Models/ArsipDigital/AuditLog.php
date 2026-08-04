<?php

namespace App\Models\ArsipDigital;

class AuditLog extends ArsipDigitalModel
{
    public const UPDATED_AT = null;

    protected $table = 'arsip_digital.audit_logs';
    protected $primaryKey = 'audit_log_id';
    protected $guarded = ['audit_log_id'];

    protected $casts = [
        'metadata' => 'array',
    ];
}
