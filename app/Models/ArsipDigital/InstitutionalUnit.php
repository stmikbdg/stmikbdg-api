<?php

namespace App\Models\ArsipDigital;

use Illuminate\Database\Eloquent\SoftDeletes;

class InstitutionalUnit extends ArsipDigitalModel
{
    use SoftDeletes;

    protected $table = 'arsip_digital.institutional_units';

    protected $primaryKey = 'unit_id';

    protected $guarded = ['unit_id'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function archives()
    {
        return $this->hasMany(InstitutionalArchive::class, 'unit_id', 'unit_id');
    }
}
