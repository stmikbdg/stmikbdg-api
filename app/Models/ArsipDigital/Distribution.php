<?php

namespace App\Models\ArsipDigital;

use Illuminate\Database\Eloquent\SoftDeletes;

class Distribution extends ArsipDigitalModel
{
    use SoftDeletes;

    protected $table = 'arsip_digital.distributions';

    protected $primaryKey = 'distribution_id';

    protected $guarded = ['distribution_id'];

    protected $casts = [
        'target_filters' => 'array',
        'target_identifiers' => 'array',
        'target_segment_ids' => 'array',
        'published_at' => 'datetime',
        'withdrawn_at' => 'datetime',
    ];

    public function originalDistribution()
    {
        return $this->belongsTo(self::class, 'original_distribution_id', 'distribution_id');
    }

    public function corrections()
    {
        return $this->hasMany(self::class, 'original_distribution_id', 'distribution_id');
    }

    public function recipients()
    {
        return $this->hasMany(DistributionRecipient::class, 'distribution_id', 'distribution_id');
    }
}
