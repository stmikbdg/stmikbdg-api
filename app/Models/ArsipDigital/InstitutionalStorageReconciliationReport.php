<?php

namespace App\Models\ArsipDigital;

class InstitutionalStorageReconciliationReport extends ArsipDigitalModel
{
    protected $table = 'arsip_digital.institutional_storage_reconciliation_reports';

    protected $primaryKey = 'reconciliation_report_id';

    protected $guarded = ['reconciliation_report_id'];

    protected $hidden = ['error_message'];

    protected $casts = [
        'total_files' => 'integer', 'checked_files' => 'integer', 'available_files' => 'integer',
        'missing_files' => 'integer', 'failed_files' => 'integer',
        'started_at' => 'datetime', 'finished_at' => 'datetime',
    ];
}
