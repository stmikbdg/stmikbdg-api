<?php

namespace App\Models\ArsipDigital;

class Setting extends ArsipDigitalModel
{
    protected $table = 'arsip_digital.settings';
    protected $primaryKey = 'setting_id';
    protected $guarded = ['setting_id'];

    protected $casts = [
        'value' => 'array',
    ];
}
