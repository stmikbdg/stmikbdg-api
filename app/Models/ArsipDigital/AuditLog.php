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

    public function note()
    {
        return $this->hasOne(AuditLogNote::class, 'audit_log_id', 'audit_log_id');
    }
}
