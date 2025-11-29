<?php

namespace App\Models\KelasKuliah;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

// ? Models
use App\Models\KRS\KRSMatkul;
use App\Models\KRS\MatKulView;
use App\Models\TahunAjaran;
use App\Models\Users\DosenView;

class KelasKuliahJoinView extends Model
{
    /**
     * Model ini digunakan untuk vkelas_kuliah_join
     * hanya digunakan untuk get data (SELECT).
     */

    protected $table = 'vkelas_kuliah_join';
    protected $connection;

    public function __construct() {
        $this->connection = config('myconfig.database.second_connection');
    }

    public function scopeGetKelasKuliahForPengumuman(Builder $query, $dosenId, $tahunIdArr, $kelasKuliahIdArr) {
        return $query->where('pengajar_id', $dosenId)
            ->whereIn('tahun_id', $tahunIdArr)
            ->whereNotIn('kelas_kuliah_id', $kelasKuliahIdArr)
            ->select('kelas_kuliah_id', 'kd_kampus', 'jns_mhs', 'mk_id', 'tahun_id')
            ->with(['matakuliah' => function ($query) {
                $query->select('mk_id', 'kur_id', 'jur_id', 'kd_mk', 'nm_mk', 'kd_jur', 'semester', 'sks');
            }])->get();
    }

    public function scopeGetKelasKuliahByDosen(Builder $query, $tahunId, $dosenId) {
        return $query->whereIn('tahun_id', $tahunId)
            ->where('pengajar_id', $dosenId)
            ->select(
                'kelas_kuliah_id', 'tahun_id', 'jur_id', 'mk_id', 'join_kelas_kuliah_id', 'kjoin_kelas', 'kelas_kuliah', 'jns_mhs', 'sts_kelas', 'pengajar_id', 'join_jur'
            )->with(['dosen' => function ($query) {
                $query->select('dosen_id', 'kd_dosen', 'nm_dosen', 'gelar');
            }])
            ->with('tahun_ajaran.minimal_presensi')
            ->with(['matakuliah' => function ($query) {
                $query->select('mk_id', 'kur_id', 'kd_mk', 'nm_mk', 'semester', 'sks', 'sts_mk', 'smt', 'kd_kur');
            }])
            ->orderBy('kelas_kuliah_id', 'DESC')
            ->get();
    }

    public function scopeGetJoinJurusanData(Builder $query, $kelasKuliahId) {
        return $query->where('kelas_kuliah_id', $kelasKuliahId)
            ->select(
                'kelas_kuliah_id', 'kjoin_kelas', 'join_jur', 'join_kelas_kuliah_id', 'pengajar_id'
            )->first();
    }

    public function scopeGetDataKelasKuliahForKuesionerPerkuliahan(Builder $query, $kelasKuliahId, $jnsMhs, $kdKampus) {
        return $query->where('kelas_kuliah_id', $kelasKuliahId)
            ->where('jns_mhs', trim($jnsMhs))
            ->where('kd_kampus', trim($kdKampus))
            ->select('kelas_kuliah_id', 'tahun_id', 'jur_id', 'mk_id', 'jns_mhs', 'kd_kampus', 'pengajar_id', 'kjoin_kelas', 'join_kelas_kuliah_id')
            ->first();
    }

    public function krsMatkul() {
        return $this->hasMany(KRSMatkul::class, 'kelas_kuliah_id', 'kelas_kuliah_id');
    }

    public function dosen() {
        return $this->belongsTo(DosenView::class, 'pengajar_id', 'dosen_id');
    }

    public function matakuliah() {
        return $this->belongsTo(MatKulView::class, 'mk_id', 'mk_id');
    }

    public function kontrak_kelas_kuliah() {
        return $this->hasMany(KontrakKelasKuliah::class, 'fk_kelas_kuliah_id', 'kelas_kuliah_id');
    }

    public function tahun_ajaran() {
        return $this->belongsTo(TahunAjaran::class, 'tahun_id', 'tahun_id');
    }
}
