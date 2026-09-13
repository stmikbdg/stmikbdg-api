<?php

namespace App\Models\ArsipDigital;

class AuditLogNoteRevision extends ArsipDigitalModel
{
    public const UPDATED_AT = null;

    protected $table = 'arsip_digital.audit_log_note_revisions';

    protected $primaryKey = 'audit_log_note_revision_id';

    protected $guarded = ['audit_log_note_revision_id'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $casts = [
        'audit_log_note_id' => 'integer',
        'version' => 'integer',
        'actor_user_id' => 'integer',
        'created_at' => 'datetime',
    ];
}
