<?php

namespace App\Models\KelasKuliah;

use App\Models\KRS\KRSMatkul;
use App\Models\KRS\MatKulView;
use App\Models\TahunAjaran;
use App\Models\Users\DosenView;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class KelasKuliahView extends Model
{
    /**
     * Model ini digunakan untuk view kelas_kuliah
     * hanya digunakan untuk get data (SELECT).
     *
     * Saat ini kolom join_kelas_kuliah_id diperlukan, tetapi di view sebelumnya tidak ada.
     * Jadi jangan lupa tambahkan kolom tersebut pada view kelas kuliah.
     */
    use HasFactory;

    protected $table = 'vkelas_kuliah';
    protected $connection;

    public function __construct() {
        $this->connection = config('myconfig.database.second_connection');
    }

    public function scopeGetMatkulDiampuArray(Builder $query, $filter) {
        return $query->where('pengajar_id', $filter['dosen_id'])
            ->where('jns_mhs', $filter['jns_mhs'])
            ->where('kd_kampus', $filter['kd_kampus'])
            ->where('tahun_id', $filter['tahun_id'])
            ->pluck('mk_id')
            ->toArray();
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
