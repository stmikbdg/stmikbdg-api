<?php

namespace App\Models\KRS;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

// ? Models - table
use App\Models\KRS\KRSMatkul;
use App\Models\TahunAjaran;
use App\Models\Users\Mahasiswa;

class KRS extends Model
{
    /**
     * Model ini digunakan untuk tabel krs
     * untuk melakukan proses-proses Create, Update, Delete.
     *
     * Koneksi database terhubung ke 'stmikbdg_dummy' tabel krs.
     */
    use HasFactory;

    public $table = 'krs';
    public $connection;

    public $primaryKey = 'krs_id';
    public $timestamps = false;
    protected $guarded = ['krs_id'];

    public function __construct() {
        $this->connection = config('myconfig.database.second_connection');
    }

    public function scopeGetLastNomorKRS(Builder $query, $similarNmrKRS) {
        $result = $query->where('nmr_krs', 'like', '%'. $similarNmrKRS . '%')
            ->orderBy('nmr_krs', 'DESC')
            ->get();

        return count($result) > 0 ? $result[0]['nmr_krs'] : false;
    }

    public function scopeGetLastSemesterKRS(Builder $query, $mahasiswa) {
        $result = $query->where('mhs_id', $mahasiswa['mhs_id'])
            ->orderBy('tanggal', 'DESC')
            ->get();

        return count($result) > 0 ? $result[0]['semester'] : false;
    }

    public function scopeCheckCurrentKRS(Builder $query, $tahunAjaranId, $mahasiswa) {
        $isLastSemesterKRS = KRS::getLastSemesterKRS($mahasiswa);
        $semester = !$isLastSemesterKRS ? 1 : $isLastSemesterKRS;

        $result = $query->where('tahun_id', $tahunAjaranId)
            ->where('mhs_id', $mahasiswa['mhs_id'])
            ->where('semester', $semester)
            ->get();

        return count($result) > 0 ? $result[0] : false;
    }

    /**
     * digunakan untuk kuesioner pekuliahan
     */
    public function scopeGetLastKRSDisetujuiWithMatkul(Builder $query, $tahunAjaranId, $mahasiswaId) {
        return $query->where('tahun_id', $tahunAjaranId)
            ->where('mhs_id', $mahasiswaId)
            ->where('sts_krs', 'S')
            ->with(['krsMatkul' => function ($query) {
                $query->select('krs_mk_id', 'krs_id', 'mk_id', 'kelas_kuliah_id')
                    ->with(['matakuliah' => function ($query) {
                        $query->select('mk_id', 'kur_id', 'jur_id', 'kd_mk', 'nm_mk', 'semester', 'sks', 'kd_kur', 'smt')
                            ->whereNotIn('kd_mk', ['IF1200', 'SI1200', 'IF1400', 'SI1400', 'IF1600', 'SI1600', 'IF1800', 'SI1800']);
                    }]);
            }])->first();
    }

    /**
     * Relasi tabel krs ke krs_matkul, one to many
     */
    public function krsMatkul() {
        return $this->hasMany(KRSMatkul::class, 'krs_id');
    }

    /**
     * Relasi tabel krs ke mahasiwa, many to one
     */
    public function mahasiswa() {
        return $this->belongsTo(Mahasiswa::class, 'mhs_id', 'mhs_id');
    }

    public function tahun_ajaran() {
        return $this->belongsTo(TahunAjaran::class, 'tahun_id', 'tahun_id');
    }
}
