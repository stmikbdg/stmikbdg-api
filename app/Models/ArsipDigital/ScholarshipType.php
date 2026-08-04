<?php

namespace App\Models\ArsipDigital;

use Illuminate\Database\Eloquent\SoftDeletes;

class ScholarshipType extends ArsipDigitalModel
{
    use SoftDeletes;

    protected $table = 'arsip_digital.scholarship_types';
    protected $primaryKey = 'scholarship_type_id';
    protected $guarded = ['scholarship_type_id'];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
