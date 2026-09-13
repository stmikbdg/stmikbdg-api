<?php

namespace App\Models\ArsipDigital;

use Illuminate\Database\Eloquent\SoftDeletes;

class SegmentMember extends ArsipDigitalModel
{
    use SoftDeletes;

    protected $table = 'arsip_digital.segment_members';
    protected $primaryKey = 'segment_member_id';
    protected $guarded = ['segment_member_id'];

    protected $casts = [
        'metadata' => 'array',
    ];
}
