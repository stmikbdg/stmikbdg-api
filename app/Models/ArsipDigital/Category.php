<?php

namespace App\Models\ArsipDigital;

use Illuminate\Database\Eloquent\SoftDeletes;

class Category extends ArsipDigitalModel
{
    use SoftDeletes;

    protected $table = 'arsip_digital.categories';
    protected $primaryKey = 'category_id';
    protected $guarded = ['category_id'];

    protected $casts = [
        'is_system' => 'boolean',
    ];
}
