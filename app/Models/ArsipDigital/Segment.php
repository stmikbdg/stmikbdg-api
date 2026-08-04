<?php

namespace App\Models\ArsipDigital;

use Illuminate\Database\Eloquent\SoftDeletes;

class Segment extends ArsipDigitalModel
{
    use SoftDeletes;

    protected $table = 'arsip_digital.segments';
    protected $primaryKey = 'segment_id';
    protected $guarded = ['segment_id'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function members()
    {
        return $this->hasMany(SegmentMember::class, 'segment_id', 'segment_id');
    }
}
