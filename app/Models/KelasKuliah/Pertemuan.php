<?php

namespace App\Models\KelasKuliah;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

// ? Models
use App\Models\KelasKuliah\Presensi;

class Pertemuan extends Model
{
    /**
     * Model ini mengarah ke tabel pertemuan di db simak baru skema kuliah
     * Digunakan apabila kelas kuliah dibuka
     */
    use HasFactory;

    protected $connection;
    protected $table = 'kuliah.pertemuan';
    protected $guarded = ['pertemuan_id'];

    public $primaryKey = 'pertemuan_id';
    public $increment = true;
    public $timestamps = false;

    public function __construct() {
        $this->connection = config('myconfig.database.first_connection');
    }

    public function scopeGetKelasDibuka(Builder $query, $kelasKuliah) {
        return $query->where('kelas_kuliah_id', $kelasKuliah['data_kelas']['kelas_kuliah_id'])
            ->where('jns_pert', $kelasKuliah['jadwal']['jns_pert'])
            ->where('dosen_id', $kelasKuliah['data_kelas']['pengajar_id'])
            ->where('kelas_dibuka', true)
            ->first();
    }

    public function scopeCekKelasPernahDibukaInSameDay(Builder $query, $kelasKuliahId, $dosenId, $currentDate) {
        return $query->where('kelas_kuliah_id', $kelasKuliahId)
            ->where('dosen_id', $dosenId)
            ->where('tanggal', $currentDate)
            ->first();
    }

    public function scopeUpdateKelasDibuka(Builder $query, $kelasKuliahIdArr, $dosenId, $statusKelas) {
        return $query->whereIn('kelas_kuliah_id', $kelasKuliahIdArr)
            ->where('dosen_id', $dosenId)
            ->update([
                'kelas_dibuka' => $statusKelas
            ]);
    }

    public function scopeGetLastKelasDibuka(Builder $query, $kelasKuliahId, $dosenId, $tanggalJadwal) {
        return $query->where('kelas_kuliah_id', $kelasKuliahId)
            ->where('dosen_id', $dosenId)
            // ->where('tanggal', $tanggalJadwal)
            ->where('kelas_dibuka', true)
            ->orderBy('pertemuan_id', 'DESC')
            ->first();
    }

    public function scopeGetPertemuanKelasDibukaWithPresensi(Builder $query, $kelasKuliahIdArr, $dosenId) {
        return $query->whereIn('kelas_kuliah_id', $kelasKuliahIdArr)
            ->where('dosen_id', $dosenId)
            ->where('kelas_dibuka', true)
            ->orderBy('pertemuan_id', 'DESC')
            ->select('pertemuan_id')
            ->with(['presensi' => function ($query) {
                $query->select('pertemuan_id', 'mhs_id', 'nim', 'nm_mhs', 'masuk')
                    ->orderBy('masuk', 'DESC');
            }])->get();
    }

    public function scopeGetCekPertemuanIdKelasDibuka(Builder $query, $kelasKuliahId) {
        return $query->where('kelas_kuliah_id', $kelasKuliahId)
            ->where('kelas_dibuka', true)
            ->orderBy('create_time', 'DESC')
            ->select('pertemuan_id')
            ->first();
    }

    public function scopeGetRiwayatPertemuanKelasKuliahByDosen(Builder $query, $kelasKuliahId, $dosenId) {
        return $query->where('kelas_kuliah_id', $kelasKuliahId)
            ->where('dosen_id', $dosenId)
            ->select('pertemuan_id', 'jns_pert', 'create_time')
            ->get();
    }

    public function presensi() {
        return $this->hasMany(Presensi::class, 'pertemuan_id', 'pertemuan_id');
    }
}
