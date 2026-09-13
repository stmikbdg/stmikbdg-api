<?php

namespace App\Models\ArsipDigital;

class OfficialDocument extends ArsipDigitalModel
{
    protected $table = 'arsip_digital.official_documents';

    protected $primaryKey = 'official_document_id';

    protected $guarded = ['official_document_id'];

    protected $hidden = ['verification_token_hash'];

    protected $appends = ['semester_summary', 'course_count'];

    protected $casts = [
        'semester' => 'integer',
        'subject_user_id' => 'integer',
        'subject_mhs_id' => 'integer',
        'academic_snapshot' => 'array',
        'snapshot_captured_at' => 'datetime',
        'issued_by_user_id' => 'integer',
        'signer_user_id' => 'integer',
        'issued_at' => 'datetime',
        'revoked_at' => 'datetime',
        'revoked_by_user_id' => 'integer',
        'replaced_by_document_id' => 'integer',
        'replaced_at' => 'datetime',
    ];

    public function getSemesterSummaryAttribute(): string
    {
        if ($this->document_type === 'khs') {
            return $this->semester ? 'Semester '.$this->semester : '-';
        }

        $semesters = collect($this->academic_snapshot['records'] ?? [])
            ->pluck('semester')
            ->filter(fn ($semester) => $semester !== null && $semester !== '')
            ->unique()
            ->sort()
            ->values();

        return $semesters->isEmpty()
            ? '-'
            : 'Semester '.$semesters->implode(', ');
    }

    public function getCourseCountAttribute(): int
    {
        return count($this->academic_snapshot['records'] ?? []);
    }

    public function replacedBy()
    {
        return $this->belongsTo(self::class, 'replaced_by_document_id', 'official_document_id');
    }

    public function distribution()
    {
        return $this->hasOne(Distribution::class, 'official_document_id', 'official_document_id');
    }

    public function file()
    {
        return $this->belongsTo(ArchiveFile::class, 'file_id', 'file_id');
    }

    public function isVerifiable(): bool
    {
        return $this->status === 'issued' && $this->verification_token_hash !== null;
    }
}
