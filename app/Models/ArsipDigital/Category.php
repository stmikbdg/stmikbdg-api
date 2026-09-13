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

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_category_id', 'category_id');
    }

    public function children()
    {
        return $this->hasMany(self::class, 'parent_category_id', 'category_id');
    }

    public function institutionalArchives()
    {
        return $this->hasMany(InstitutionalArchive::class, 'category_id', 'category_id');
    }
}
