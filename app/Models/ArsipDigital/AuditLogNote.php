<?php

namespace App\Models\ArsipDigital;

class AuditLogNote extends ArsipDigitalModel
{
    protected $table = 'arsip_digital.audit_log_notes';

    protected $primaryKey = 'audit_log_note_id';

    protected $guarded = ['audit_log_note_id'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $casts = [
        'audit_log_id' => 'integer',
        'creator_user_id' => 'integer',
        'last_editor_user_id' => 'integer',
        'current_version' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function revisions()
    {
        return $this->hasMany(AuditLogNoteRevision::class, 'audit_log_note_id', 'audit_log_note_id');
    }
}
