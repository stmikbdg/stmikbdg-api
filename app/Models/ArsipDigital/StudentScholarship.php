<?php

namespace App\Models\ArsipDigital;

use Illuminate\Database\Eloquent\SoftDeletes;

class StudentScholarship extends ArsipDigitalModel
{
    use SoftDeletes;

    protected $table = 'arsip_digital.student_scholarships';
    protected $primaryKey = 'student_scholarship_id';
    protected $guarded = ['student_scholarship_id'];

    protected $casts = [
        'metadata' => 'array',
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    public function scholarshipType()
    {
        return $this->belongsTo(ScholarshipType::class, 'scholarship_type_id', 'scholarship_type_id');
    }
}
